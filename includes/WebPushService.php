<?php
/**
 * MasakaMart — Web Push sender (RFC 8291 aes128gcm payload encryption +
 * RFC 8292 VAPID authentication), pure PHP/OpenSSL, no external library.
 *
 * IMPORTANT — this class has NOT been exercised against a live browser
 * subscription in this environment (no PHP runtime or HTTPS endpoint was
 * available while writing it). It was implemented carefully against the
 * published specs, but push crypto is easy to get subtly wrong. Test it
 * for real after deploying: subscribe a real browser (see assets/js/push.js)
 * and trigger one notification, then check tools/push_test.php's output
 * and your browser's actual notification tray.
 *
 * Requires PHP 8.1+ (openssl_pkey_derive). On older PHP this silently
 * no-ops — it never throws, so it can never break any other feature.
 */

if (!class_exists('WebPushService')) {
    class WebPushService
    {
        public static function configured(): bool
        {
            return VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== ''
                && function_exists('openssl_pkey_derive')
                && extension_loaded('openssl');
        }

        /**
         * Send one push message to one subscription.
         *
         * @param array{endpoint:string,p256dh:string,auth:string} $subscription
         * @return array{ok:bool,http_status:?int,error:?string,expired:bool}
         */
        public static function send(array $subscription, string $title, string $body, ?string $url = null): array
        {
            if (!self::configured()) {
                return ['ok' => false, 'http_status' => null, 'error' => 'vapid_not_configured', 'expired' => false];
            }

            try {
                $endpoint = (string)$subscription['endpoint'];
                $payloadJson = json_encode([
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                ], JSON_UNESCAPED_SLASHES);

                $encrypted = self::encryptPayload(
                    $payloadJson,
                    (string)$subscription['p256dh'],
                    (string)$subscription['auth']
                );

                $endpointOrigin = self::originOf($endpoint);
                $jwt = self::buildVapidJwt($endpointOrigin);

                $headers = [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'TTL: 604800', // 7 days — push service may drop it sooner if the device is offline longer
                    'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
                ];

                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $encrypted,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $responseBody = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError !== '') {
                    return ['ok' => false, 'http_status' => null, 'error' => 'curl:' . $curlError, 'expired' => false];
                }

                // 404/410 = push service says this subscription is gone for good.
                $expired = in_array($status, [404, 410], true);
                $ok = $status >= 200 && $status < 300;

                return [
                    'ok' => $ok,
                    'http_status' => $status,
                    'error' => $ok ? null : ('http_' . $status . ':' . substr((string)$responseBody, 0, 200)),
                    'expired' => $expired,
                ];
            } catch (Throwable $e) {
                error_log('[webpush] send failed: ' . $e->getMessage());
                return ['ok' => false, 'http_status' => null, 'error' => 'exception', 'expired' => false];
            }
        }

        private static function originOf(string $url): string
        {
            $parts = parse_url($url);
            return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        }

        /** RFC 8292 VAPID JWT, ES256-signed, raw r||s signature (JWA format). */
        private static function buildVapidJwt(string $audience): string
        {
            $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
            $payload = self::b64url(json_encode([
                'aud' => $audience,
                'exp' => time() + 12 * 3600,
                'sub' => VAPID_SUBJECT,
            ]));
            $unsigned = $header . '.' . $payload;

            $privateKeyPem = self::pemFromRawEcPrivateKey(self::b64urlDecode(VAPID_PRIVATE_KEY));
            $pkey = openssl_pkey_get_private($privateKeyPem);
            if ($pkey === false) {
                throw new RuntimeException('Invalid VAPID private key.');
            }

            $der = '';
            if (!openssl_sign($unsigned, $der, $pkey, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('VAPID signing failed.');
            }

            return $unsigned . '.' . self::b64url(self::derEcdsaSignatureToRaw($der));
        }

        /** RFC 8291: encrypt the JSON payload for aes128gcm content-encoding. */
        private static function encryptPayload(string $plaintext, string $p256dhB64, string $authB64): string
        {
            $uaPublicRaw = self::b64urlDecode($p256dhB64); // 65 bytes, uncompressed point
            $authSecret = self::b64urlDecode($authB64);    // 16 bytes

            // Ephemeral application-server EC keypair for this message only.
            $asKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($asKey === false) {
                throw new RuntimeException('Could not generate ephemeral EC key.');
            }
            $asDetails = openssl_pkey_get_details($asKey);
            $asPublicRaw = "\x04"
                . str_pad($asDetails['ec']['x'], 32, "\0", STR_PAD_LEFT)
                . str_pad($asDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);

            $uaPublicKey = openssl_pkey_get_public(self::pemFromRawEcPublicKey($uaPublicRaw));
            if ($uaPublicKey === false) {
                throw new RuntimeException('Invalid subscription public key.');
            }

            $sharedSecret = openssl_pkey_derive($uaPublicKey, $asKey, 32);
            if ($sharedSecret === false || strlen($sharedSecret) !== 32) {
                throw new RuntimeException('ECDH derivation failed.');
            }

            // Stage 1 — RFC 8291 key combination.
            $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $asPublicRaw;
            $prkKey = hash_hkdf('sha256', $sharedSecret, 32, '', $authSecret);
            $ikm = self::hkdfExpandOnly($prkKey, $keyInfo, 32);

            // Stage 2 — RFC 8188 aes128gcm content-coding key derivation.
            $salt = random_bytes(16);
            $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
            $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

            // Single-record body: plaintext + 0x02 delimiter (last record).
            $recordPlaintext = $plaintext . "\x02";

            $tag = '';
            $ciphertext = openssl_encrypt($recordPlaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if ($ciphertext === false) {
                throw new RuntimeException('AES-128-GCM encryption failed.');
            }

            $recordSize = pack('N', 4096);
            $keyIdLen = pack('C', strlen($asPublicRaw));

            return $salt . $recordSize . $keyIdLen . $asPublicRaw . $ciphertext . $tag;
        }

        /** hash_hkdf() always does extract+expand; here we already have the PRK, so run expand only. */
        private static function hkdfExpandOnly(string $prk, string $info, int $length): string
        {
            $hashLen = 32; // sha256
            $n = (int)ceil($length / $hashLen);
            $t = '';
            $okm = '';
            for ($i = 1; $i <= $n; $i++) {
                $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
                $okm .= $t;
            }
            return substr($okm, 0, $length);
        }

        private static function pemFromRawEcPrivateKey(string $rawD): string
        {
            // Wrap the raw 32-byte private scalar as a SEC1 EC private key,
            // reusing the public VAPID key so the point is present too.
            $pub = self::b64urlDecode(VAPID_PUBLIC_KEY);
            $d = str_pad($rawD, 32, "\0", STR_PAD_LEFT);

            $ec = new ASN1SequenceBuilder();
            $ec->addInteger(1);
            $ec->addOctetString($d);
            $ec->addExplicit(0, self::oidPrime256v1());
            $ec->addExplicit(1, self::bitString($pub));
            $der = $ec->build();

            return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END EC PRIVATE KEY-----\n";
        }

        private static function pemFromRawEcPublicKey(string $rawPoint): string
        {
            // SubjectPublicKeyInfo wrapping a P-256 point, built by hand (no
            // external ASN.1 library available) — see ASN1SequenceBuilder below.
            $algId = new ASN1SequenceBuilder();
            $algId->addRawOid("\x2a\x86\x48\xce\x3d\x02\x01");       // ecPublicKey
            $algId->addRawOid("\x2a\x86\x48\xce\x3d\x03\x01\x07");   // prime256v1
            $spki = new ASN1SequenceBuilder();
            $spki->addRaw($algId->build());
            $spki->addRaw(self::bitString($rawPoint));
            $der = $spki->build();

            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
        }

        private static function oidPrime256v1(): string
        {
            return "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        }

        private static function bitString(string $content): string
        {
            $body = "\x00" . $content; // 0 unused bits
            return "\x03" . self::asn1Len(strlen($body)) . $body;
        }

        private static function asn1Len(int $len): string
        {
            if ($len < 128) {
                return chr($len);
            }
            $bytes = ltrim(pack('N', $len), "\x00");
            return chr(0x80 | strlen($bytes)) . $bytes;
        }

        /** Convert a DER ECDSA signature to the fixed 64-byte r||s form JWS/ES256 requires. */
        private static function derEcdsaSignatureToRaw(string $der): string
        {
            $offset = 2; // skip SEQUENCE tag + length
            $r = self::readDerInteger($der, $offset);
            $s = self::readDerInteger($der, $offset);
            return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
        }

        private static function readDerInteger(string $der, int &$offset): string
        {
            if ($der[$offset] !== "\x02") {
                throw new RuntimeException('Malformed ECDSA signature (expected INTEGER).');
            }
            $offset++;
            $len = ord($der[$offset]);
            $offset++;
            $value = substr($der, $offset, $len);
            $offset += $len;
            return ltrim($value, "\x00");
        }

        private static function b64url(string $bin): string
        {
            return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        }

        private static function b64urlDecode(string $str): string
        {
            $str = strtr($str, '-_', '+/');
            $pad = strlen($str) % 4;
            if ($pad > 0) {
                $str .= str_repeat('=', 4 - $pad);
            }
            return (string)base64_decode($str);
        }
    }
}

/**
 * Minimal hand-rolled ASN.1 DER SEQUENCE builder — just enough to construct
 * the two small, fixed-shape EC key structures above without a dependency.
 */
if (!class_exists('ASN1SequenceBuilder')) {
    class ASN1SequenceBuilder
    {
        private array $parts = [];

        public function addInteger(int $n): void
        {
            $this->parts[] = "\x02\x01" . chr($n);
        }

        public function addOctetString(string $bin): void
        {
            $this->parts[] = "\x04" . $this->len(strlen($bin)) . $bin;
        }

        public function addRawOid(string $oidBody): void
        {
            $this->parts[] = "\x06" . $this->len(strlen($oidBody)) . $oidBody;
        }

        public function addExplicit(int $tagNumber, string $innerDer): void
        {
            $tag = chr(0xA0 | $tagNumber);
            $this->parts[] = $tag . $this->len(strlen($innerDer)) . $innerDer;
        }

        public function addRaw(string $der): void
        {
            $this->parts[] = $der;
        }

        public function build(): string
        {
            $body = implode('', $this->parts);
            return "\x30" . $this->len(strlen($body)) . $body;
        }

        private function len(int $len): string
        {
            if ($len < 128) {
                return chr($len);
            }
            $bytes = ltrim(pack('N', $len), "\x00");
            return chr(0x80 | strlen($bytes)) . $bytes;
        }
    }
}
