<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $slideId = (int)($_POST['slide_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $eyebrow = trim($_POST['eyebrow'] ?? '');
        $buttonText = trim($_POST['button_text'] ?? '');
        $buttonLink = trim($_POST['button_link'] ?? '');
        $mediaType = in_array($_POST['media_type'] ?? 'image', ['image', 'video'], true) ? $_POST['media_type'] : 'image';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            flash('error', 'Each slide needs a title.');
            redirect(APP_URL . '/admin/hero_slider.php');
        }

        $uploadedMedia = null;
        try {
            $uploadedMedia = handle_hero_media_upload('media');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect(APP_URL . '/admin/hero_slider.php');
        }

        if ($slideId > 0) {
            $existing = $db->prepare("SELECT media_path, media_type FROM hero_slides WHERE slide_id = :id LIMIT 1");
            $existing->execute(['id' => $slideId]);
            $existingSlide = $existing->fetch();

            $mediaPath = $uploadedMedia ?? ($existingSlide['media_path'] ?? null);
            if ($uploadedMedia && !empty($existingSlide['media_path']) && $existingSlide['media_path'] !== $uploadedMedia) {
                delete_uploaded_media($existingSlide['media_path']);
            }

            $db->prepare(
                "UPDATE hero_slides SET title=:title, subtitle=:subtitle, eyebrow=:eyebrow, button_text=:btnText,
                 button_link=:btnLink, media_type=:mediaType, media_path=:mediaPath, sort_order=:sortOrder, is_active=:isActive
                 WHERE slide_id=:id"
            )->execute([
                'title' => $title,
                'subtitle' => $subtitle !== '' ? $subtitle : null,
                'eyebrow' => $eyebrow !== '' ? $eyebrow : null,
                'btnText' => $buttonText !== '' ? $buttonText : null,
                'btnLink' => $buttonLink !== '' ? $buttonLink : null,
                'mediaType' => $mediaType,
                'mediaPath' => $mediaPath,
                'sortOrder' => $sortOrder,
                'isActive' => $isActive,
                'id' => $slideId,
            ]);
              if ($isActive === 1) {
                $db->prepare("UPDATE hero_slides SET is_active = 0 WHERE slide_id <> :id")
                   ->execute(['id' => $slideId]);
              }
            flash('success', 'Hero slide updated.');
        } else {
            if ($uploadedMedia === null) {
                flash('error', 'Please upload an image or video for the new hero slide.');
                redirect(APP_URL . '/admin/hero_slider.php');
            }

            $db->prepare(
                "INSERT INTO hero_slides (title, subtitle, eyebrow, button_text, button_link, media_type, media_path, sort_order, is_active)
                 VALUES (:title, :subtitle, :eyebrow, :btnText, :btnLink, :mediaType, :mediaPath, :sortOrder, :isActive)"
            )->execute([
                'title' => $title,
                'subtitle' => $subtitle !== '' ? $subtitle : null,
                'eyebrow' => $eyebrow !== '' ? $eyebrow : null,
                'btnText' => $buttonText !== '' ? $buttonText : null,
                'btnLink' => $buttonLink !== '' ? $buttonLink : null,
                'mediaType' => $mediaType,
                'mediaPath' => $uploadedMedia,
                'sortOrder' => $sortOrder,
                'isActive' => $isActive,
            ]);
            if ($isActive === 1) {
                $newSlideId = (int)$db->lastInsertId();
                $db->prepare("UPDATE hero_slides SET is_active = 0 WHERE slide_id <> :id")
                   ->execute(['id' => $newSlideId]);
            }
            flash('success', 'Hero slide added.');
        }

        redirect(APP_URL . '/admin/hero_slider.php');
    }

    if ($action === 'toggle') {
        $slideId = (int)$_POST['slide_id'];
        $slideStmt = $db->prepare("SELECT is_active FROM hero_slides WHERE slide_id = :id LIMIT 1");
        $slideStmt->execute(['id' => $slideId]);
        $slide = $slideStmt->fetch();
        if (!$slide) {
            flash('error', 'Hero slide not found.');
        } elseif ((int)$slide['is_active'] === 1) {
            flash('error', 'Keep one hero slide active on the storefront.');
        } else {
            $db->exec("UPDATE hero_slides SET is_active = 0");
            $db->prepare("UPDATE hero_slides SET is_active = 1 WHERE slide_id = :id")
               ->execute(['id' => $slideId]);
            flash('success', 'Hero slide activated. The previous hero remains available but is now inactive.');
        }
        redirect(APP_URL . '/admin/hero_slider.php');
    }

    if ($action === 'delete') {
        $slideId = (int)($_POST['slide_id'] ?? 0);
        $row = $db->prepare("SELECT media_path FROM hero_slides WHERE slide_id = :id LIMIT 1");
        $row->execute(['id' => $slideId]);
        $mediaPath = $row->fetch()['media_path'] ?? null;
        $db->prepare("DELETE FROM hero_slides WHERE slide_id = :id")->execute(['id' => $slideId]);
        delete_uploaded_media($mediaPath);
        flash('success', 'Slide deleted.');
        redirect(APP_URL . '/admin/hero_slider.php');
    }
}

$editSlide = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM hero_slides WHERE slide_id = :id LIMIT 1");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editSlide = $stmt->fetch();
}

$slides = $db->query("SELECT * FROM hero_slides ORDER BY sort_order ASC, slide_id ASC")->fetchAll();

$pageTitle = 'Hero Slider — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Hero Slider</h1>
<p class="field-hint">Only one hero slide is active on the storefront at a time. Activating another slide keeps the previous slide available but marks it inactive.</p>
              <td><span class="status-pill status-<?= $slide['is_active'] ? 'paid' : 'failed' ?>"><?= $slide['is_active'] ? 'Active' : 'Inactive' ?></span></td>

<div class="admin-panel">
  <h2><?= $editSlide ? 'Edit Slide' : 'Add Slide' ?></h2>
  <form method="post" enctype="multipart/form-data" class="admin-form admin-form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editSlide): ?><input type="hidden" name="slide_id" value="<?= (int)$editSlide['slide_id'] ?>"><?php endif; ?>

    <div><label>Title</label><input type="text" name="title" value="<?= e($editSlide['title'] ?? '') ?>" required></div>
    <div><label>Eyebrow</label><input type="text" name="eyebrow" value="<?= e($editSlide['eyebrow'] ?? '') ?>" placeholder="Fresh & Healthy"></div>
    <div class="span-2"><label>Subtitle</label><textarea name="subtitle" rows="3"><?= e($editSlide['subtitle'] ?? '') ?></textarea></div>
    <div><label>Button Text</label><input type="text" name="button_text" value="<?= e($editSlide['button_text'] ?? '') ?>" placeholder="Shop now"></div>
    <div><label>Button Link</label><input type="text" name="button_link" value="<?= e($editSlide['button_link'] ?? '') ?>" placeholder="/products.php"></div>
    <div><label>Sort Order</label><input type="number" name="sort_order" value="<?= e((string)($editSlide['sort_order'] ?? 0)) ?>"></div>
    <div><label>Media Type</label>
      <select name="media_type">
        <option value="image" <?= (($editSlide['media_type'] ?? 'image') === 'image') ? 'selected' : '' ?>>Image</option>
        <option value="video" <?= (($editSlide['media_type'] ?? '') === 'video') ? 'selected' : '' ?>>Video</option>
      </select>
    </div>
    <div><label>Upload Media</label><input type="file" name="media" accept="image/png,image/jpeg,image/webp,video/mp4,video/webm,video/quicktime"></div>
    <div>
      <label><input type="checkbox" name="is_active" <?= (($editSlide['is_active'] ?? 1) == 1) ? 'checked' : '' ?>> Active on storefront</label>
    </div>

    <div class="span-2">
      <button type="submit" class="btn btn-cta"><?= $editSlide ? 'Save Slide' : 'Add Slide' ?></button>
      <?php if ($editSlide): ?><a href="<?= APP_URL ?>/admin/hero_slider.php" class="btn btn-outline">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="admin-panel">
  <h2>Current Slides</h2>
  <?php if (empty($slides)): ?>
    <p class="empty-state-sm">No hero slides yet.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th>Title</th><th>Type</th><th>Order</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($slides as $slide): ?>
            <tr>
              <td><?= e($slide['title']) ?></td>
              <td><?= e($slide['media_type']) ?></td>
              <td><?= (int)$slide['sort_order'] ?></td>
              <td><span class="status-pill status-<?= $slide['is_active'] ? 'paid' : 'failed' ?>"><?= $slide['is_active'] ? 'Active' : 'Hidden' ?></span></td>
              <td class="admin-row-actions">
                <a href="?edit=<?= (int)$slide['slide_id'] ?>">Edit</a>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="slide_id" value="<?= (int)$slide['slide_id'] ?>"><button type="submit" class="link-btn"><?= $slide['is_active'] ? 'Hide' : 'Show' ?></button></form>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this slide?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="slide_id" value="<?= (int)$slide['slide_id'] ?>"><button type="submit" class="link-btn link-btn-danger">Delete</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
