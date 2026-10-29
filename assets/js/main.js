// KATALE — main.js (vanilla JS, no build step)
document.addEventListener('DOMContentLoaded', function () {

  function trackEcommerce(eventName, payload) {
    if (window.KATALE_ANALYTICS_READY && typeof window.gtag === 'function') {
      window.gtag('event', eventName, payload || {});
    }
  }

  document.querySelectorAll('input[name="delivery_method"]').forEach(input => {
    input.addEventListener('change', function () {
      if (this.checked) trackEcommerce('add_shipping_info', {shipping_tier: this.value});
    });
  });

  document.querySelectorAll('input[name="payment_method"]').forEach(input => {
    input.addEventListener('change', function () {
      if (this.checked) trackEcommerce('add_payment_info', {payment_type: this.value});
    });
  });

  function showToast(message, type) {
    const stack = document.getElementById('toastStack');
    if (!stack || !message) return;
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'success');
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    stack.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    setTimeout(() => {
      toast.classList.remove('is-visible');
      setTimeout(() => toast.remove(), 280);
    }, 3200);
  }

  function syncMobileCartBadge(count) {
    const badge = document.getElementById('kataleMobileCartBadge');
    const headerBadge = document.getElementById('cartCount');
    const value = Number(count || 0);
    if (headerBadge) headerBadge.textContent = value;
    if (badge) {
      badge.textContent = value;
      badge.style.display = value > 0 ? 'inline-flex' : 'none';
    }
  }

  document.querySelectorAll('[data-back-link]').forEach(link => {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      const fallback = this.dataset.backFallback || APP_BASE_URL + '/index.php';
      if (window.history.length > 1) {
        window.history.back();
      } else {
        window.location.href = fallback;
      }
    });
  });

  const notificationPanel = document.getElementById('notificationPanel');
  const notificationToggle = document.querySelector('.notification-toggle');

  if (notificationToggle && notificationPanel) {
    notificationToggle.addEventListener('click', function () {
      const open = notificationPanel.classList.toggle('open');
      notificationToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (!notificationPanel.contains(e.target) && !notificationToggle.contains(e.target)) {
        notificationPanel.classList.remove('open');
        notificationToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }

  function renderNotificationPanelItems(items) {
    if (!items || !items.length) {
      return '<div class="notification-item empty">No notifications yet.</div>';
    }
    return items.map(item => `
      <a href="${escapeHtml(item.link)}" class="notification-item ${item.is_read ? '' : 'unread'}">
        <div class="notification-item-brand">${escapeHtml(item.brand || 'KATALE')}</div>
        <div class="notification-meta">
          <span class="notification-icon">${item.icon || '🔔'}</span>
          <div class="notification-copy">
            <span class="notification-type-label">${escapeHtml(item.type_label || '')}</span>
            <span class="notification-title">${escapeHtml(item.title || '')}</span>
            ${item.order_number ? `<span class="notification-order-ref">${escapeHtml(item.order_number)}</span>` : ''}
          </div>
        </div>
        <small>${escapeHtml(item.date || '')}</small>
      </a>
    `).join('');
  }

  function refreshNotifications() {
    fetch(APP_BASE_URL + '/notifications_poll.php', {
      headers: { 'Accept': 'application/json' }
    })
      .then(r => r.json())
      .then(data => {
        const badge = document.querySelector('.notification-badge');
        const count = Number(data.count || 0);
        if (badge) {
          badge.textContent = count;
          badge.style.display = count > 0 ? 'inline-flex' : 'none';
        } else if (count > 0 && notificationToggle) {
          const span = document.createElement('span');
          span.className = 'notification-badge';
          span.textContent = count;
          notificationToggle.appendChild(span);
        }
        if (notificationPanel) {
          notificationPanel.innerHTML = `
            <div class="notification-panel-header">
              <strong>Notifications</strong>
              <a href="${APP_BASE_URL}/notifications.php">View all</a>
            </div>
            ${renderNotificationPanelItems(data.items)}
          `;
        }
      })
      .catch(() => {});
  }

  if (notificationToggle) {
    refreshNotifications();
    setInterval(refreshNotifications, 30000);
  }

  /* ---- Mobile nav toggle ---- */
  const navToggle = document.getElementById('navToggle');
  const categoryNav = document.getElementById('categoryNav');
  if (navToggle && categoryNav) {
    navToggle.addEventListener('click', () => categoryNav.classList.toggle('open'));
  }

  document.querySelectorAll('.category-tile-image img').forEach(image => {
    function showCategoryFallback() {
      image.hidden = true;
      const fallback = image.parentElement.querySelector('.category-tile-fallback');
      if (fallback) fallback.hidden = false;
    }

    image.addEventListener('error', showCategoryFallback);
    if (image.complete && image.naturalWidth === 0) {
      showCategoryFallback();
    }
  });

  /* ---- Search autocomplete ---- */
  const searchInput = document.getElementById('searchInput');
  const suggestions = document.getElementById('searchSuggestions');
  let searchTimer = null;

  if (searchInput && suggestions) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      const term = this.value.trim();
      if (term.length < 2) {
        suggestions.classList.remove('active');
        suggestions.innerHTML = '';
        return;
      }
      searchTimer = setTimeout(() => {
        fetch(APP_BASE_URL + '/products/search_suggest.php?q=' + encodeURIComponent(term))
          .then(r => r.json())
          .then(items => {
            if (!items.length) {
              suggestions.classList.remove('active');
              suggestions.innerHTML = '';
              return;
            }
            suggestions.innerHTML = items.map(i =>
              `<a href="${APP_BASE_URL}/product.php?slug=${encodeURIComponent(i.slug)}">${i.name}</a>`
            ).join('');
            suggestions.classList.add('active');
          })
          .catch(() => {});
      }, 200);
    });

    document.addEventListener('click', function (e) {
      if (!suggestions.contains(e.target) && e.target !== searchInput) {
        suggestions.classList.remove('active');
      }
    });
  }

  /* ---- Add-to-cart forms (product cards + product detail page) ---- */
  document.querySelectorAll('.add-to-cart-form').forEach(form => {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      const formData = new FormData(form);
      formData.set('action', 'add');
      const btn = form.querySelector('button[type=submit]');
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Adding…';

      fetch(APP_BASE_URL + '/cart/cart_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          btn.textContent = originalText;
          if (data.ok) {
            const cartCount = document.getElementById('cartCount');
            if (cartCount) cartCount.textContent = data.cart_count;
            syncMobileCartBadge(data.cart_count);
            showToast('Product added to cart', 'success');
            const card = form.closest('[data-product-id]');
            trackEcommerce('add_to_cart', {currency: 'UGX', value: Number(card && card.dataset.productPrice || 0), items: [{item_id: String(form.dataset.productId || ''), item_name: String(card && card.dataset.productName || ''), price: Number(card && card.dataset.productPrice || 0), quantity: Number(formData.get('quantity') || 1)}]});
            btn.textContent = 'Added ✓';
            setTimeout(() => { btn.textContent = originalText; }, 1200);
          } else {
            showToast(data.error || 'Could not add to cart.', 'error');
          }
        })
        .catch(() => {
          btn.disabled = false;
          btn.textContent = originalText;
          alert('Something went wrong. Please try again.');
        });
    });
  });

  document.querySelectorAll('.product-card a.product-card-img, .product-card a.product-name').forEach(link => {
    link.addEventListener('click', function () {
      const card = link.closest('[data-product-id]');
      if (!card) return;
      trackEcommerce('select_item', {item_list_name: 'Product list', items: [{item_id: String(card.dataset.productId || ''), item_name: String(card.dataset.productName || ''), price: Number(card.dataset.productPrice || 0), currency: 'UGX'}]});
    });
  });

  /* ---- Cart page: quantity update / remove ---- */
  const csrfInput = document.getElementById('csrfToken');
  const csrfToken = csrfInput ? csrfInput.value : '';

  function postCartAction(action, productId, quantity) {
    const formData = new FormData();
    formData.set('csrf_token', csrfToken);
    formData.set('action', action);
    formData.set('product_id', productId);
    if (quantity !== undefined) formData.set('quantity', quantity);
    return fetch(APP_BASE_URL + '/cart/cart_action.php', { method: 'POST', body: formData }).then(r => r.json());
  }

  function bindQtyInput(input) {
    input.addEventListener('change', function () {
      const productId = this.dataset.productId;
      const qty = parseFloat(this.value);
      postCartAction('update', productId, qty).then(data => {
        if (data.ok) {
          const cartCountEl = document.getElementById('cartCount');
          if (cartCountEl) cartCountEl.textContent = data.cart_count;
          syncMobileCartBadge(data.cart_count);
          const subtotalEl = document.getElementById('cartSubtotal');
          if (subtotalEl) subtotalEl.textContent = data.subtotal_formatted;
          const preview = document.getElementById('cartGrandPreview');
          if (preview) preview.textContent = data.subtotal_formatted;
          showToast('Cart updated', 'success');
          window.location.reload();
        } else {
          showToast(data.error || 'Could not update quantity.', 'error');
          window.location.reload();
        }
      });
    });
  }

  document.querySelectorAll('.cart-qty-input').forEach(bindQtyInput);

  document.querySelectorAll('.qty-minus, .qty-plus').forEach(btn => {
    btn.addEventListener('click', function () {
      const productId = this.dataset.productId;
      const wrap = this.closest('.cart-qty-controls');
      if (!wrap) return;
      const input = wrap.querySelector('.cart-qty-input');
      if (!input) return;
      const step = parseFloat(input.step || '1') || 1;
      const min = parseFloat(input.min || '1') || 1;
      const max = parseFloat(input.max || '9999') || 9999;
      let val = parseFloat(input.value || min);
      val = this.classList.contains('qty-minus') ? val - step : val + step;
      val = Math.max(min, Math.min(max, val));
      input.value = String(val);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  function getCsrfToken() {
    const globalToken = document.getElementById('kataleCsrfToken');
    if (globalToken && globalToken.value) return globalToken.value;
    const input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function syncWishlistBadge(count) {
    const badge = document.getElementById('kataleMobileWishlistBadge');
    const value = Number(count || 0);
    if (badge) {
      badge.textContent = value;
      badge.style.display = value > 0 ? 'inline-flex' : 'none';
    }
  }

  function setHeartState(btn, wishlisted) {
    btn.classList.toggle('is-active', wishlisted);
    btn.setAttribute('aria-pressed', wishlisted ? 'true' : 'false');
    btn.setAttribute('aria-label', wishlisted ? 'Remove from wishlist' : 'Add to wishlist');
    const icon = btn.querySelector('.wishlist-heart-icon');
    if (icon) icon.textContent = wishlisted ? '♥' : '♡';
  }

  document.querySelectorAll('[data-wishlist-toggle]').forEach(btn => {
    const pid = btn.getAttribute('data-wishlist-toggle');
    if (window.KATALE_WISHLIST_IDS && window.KATALE_WISHLIST_IDS.includes(Number(pid))) {
      setHeartState(btn, true);
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const formData = new FormData();
      formData.set('csrf_token', getCsrfToken());
      formData.set('product_id', pid);

      fetch(APP_BASE_URL + '/customer/wishlist_toggle.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then(r => r.json())
        .then(data => {
          if (data.login_required) {
            const goLogin = confirm((data.message || 'Please log in.') + '\n\nGo to login now?');
            if (goLogin) window.location.href = data.login_url || (APP_BASE_URL + '/auth/login.php');
            return;
          }
          if (!data.ok) {
            alert(data.error || data.message || 'Could not update wishlist.');
            return;
          }
          setHeartState(btn, !!data.wishlisted);
          syncWishlistBadge(data.wishlist_count);
          btn.classList.add('wishlist-pulse');
          setTimeout(() => btn.classList.remove('wishlist-pulse'), 450);
        })
        .catch(() => alert('Could not update wishlist. Please try again.'));
    });
  });

  document.querySelectorAll('[data-toggle-password]').forEach(toggle => {
    toggle.addEventListener('click', function () {
      const input = document.getElementById(this.getAttribute('data-toggle-password'));
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      this.textContent = show ? 'Hide' : 'Show';
      this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  document.querySelectorAll('.cart-remove-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const productId = this.dataset.productId;
      postCartAction('remove', productId).then(data => {
        if (data.ok) {
          trackEcommerce('remove_from_cart', {currency: 'UGX', items: [{item_id: String(productId), quantity: 1}]});
          showToast('Item removed from cart', 'success');
          window.location.reload();
        }
      });
    });
  });

  document.querySelectorAll('.order-tab').forEach(tab => {
    tab.addEventListener('click', function () {
      const filter = this.dataset.orderFilter || 'all';
      document.querySelectorAll('.order-tab').forEach(t => {
        const active = t === tab;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.querySelectorAll('.order-card').forEach(card => {
        const status = card.dataset.orderStatus || '';
        const show = filter === 'all' || status === filter;
        card.style.display = show ? '' : 'none';
      });
    });
  });
});
