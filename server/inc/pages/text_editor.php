<?php
/** The text editor's form fields, inside media_edit.php's form ($m = the text media item). */
$d = text_media_design($m);
$opts = function (array $choices, string $current): string {
    $out = '';
    foreach ($choices as $k => $label) {
        $out .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h(is_array($label) ? $label[0] : $label) . '</option>';
    }
    return $out;
};
$lineRow = function (array $l) use ($opts): string {
    return '<div class="text-line-row" data-style="' . h($l['style']) . '">'
        . '<textarea name="line_text[]" rows="1" placeholder="Text">' . h($l['text']) . '</textarea>'
        . '<select name="line_style[]" title="Style">' . $opts(PP_TEXT_STYLES, $l['style']) . '</select>'
        . '<select name="line_size[]" title="Size">' . $opts(PP_TEXT_SIZES, (string)(float)$l['size']) . '</select>'
        . '<input type="color" name="line_color[]" value="' . h($l['color']) . '" title="Text colour">'
        . '<input type="color" name="line_bg[]" value="' . h($l['bg']) . '" title="Price tag colour" class="badge-only">'
        . '<span class="text-line-tools"><button type="button" class="btn btn-sm" data-line="up" title="Move up">▲</button>'
        . '<button type="button" class="btn btn-sm" data-line="down" title="Move down">▼</button>'
        . '<button type="button" class="btn btn-sm btn-danger" data-line="remove" title="Remove">✕</button></span></div>';
};
?>
<fieldset class="text-group">
  <legend>Lines</legend>
  <div id="text-lines"><?php foreach ($d['lines'] as $l) echo $lineRow($l); ?></div>
  <template id="text-line-template"><?= $lineRow(['text' => '', 'style' => 'text', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff']) ?></template>
  <button type="button" class="btn btn-sm" data-line="add">＋ Add line</button>
  <p class="muted small">Press Enter in a line for a line break. Empty lines are left out.</p>
</fieldset>

<fieldset class="text-group">
  <legend>Text</legend>
  <div class="row wrap">
    <label>Font <select name="font"><?= $opts(PP_TEXT_FONTS, $d['font']) ?></select></label>
    <label>Across <select name="align"><?= $opts(['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'], $d['align']) ?></select></label>
    <label>Down <select name="valign"><?= $opts(['top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom'], $d['valign']) ?></select></label>
  </div>
  <label class="check"><input type="checkbox" name="shadow" value="1" <?= $d['shadow'] ? 'checked' : '' ?>> Shadow under the text</label>
</fieldset>

<fieldset class="text-group">
  <legend>Background</legend>
  <div class="row wrap">
    <label>Colour <input type="color" name="bg1" value="<?= h($d['bg1']) ?>"></label>
    <label>Fades to <input type="color" name="bg2" value="<?= h($d['bg2']) ?>"></label>
    <label class="grow">Direction <input type="range" name="angle" min="0" max="360" step="15" value="<?= $d['angle'] ?>"></label>
  </div>
</fieldset>

<fieldset class="text-group">
  <legend>Photo</legend>
  <?php if ($d['photo'] !== ''): ?>
    <div class="row wrap">
      <img class="text-photo-thumb" src="media.php/<?= $id ?>/<?= h($d['photo']) ?>" alt="">
      <label class="check"><input type="checkbox" name="photo_remove" value="1"> Remove the photo</label>
    </div>
  <?php endif; ?>
  <label><?= $d['photo'] !== '' ? 'Replace with' : 'Add a photo' ?> <input type="file" name="photo_file" accept="image/*"></label>
  <?php if ($d['photo'] === ''): ?><p class="muted small">A new photo shows in the preview after Save.</p><?php endif; ?>
  <div class="row wrap">
    <label>Place <select name="photo_mode"><?= $opts(PP_TEXT_PHOTO_MODES, $d['photo_mode']) ?></select></label>
    <label class="grow">Focus across <input type="range" name="photo_x" min="0" max="100" value="<?= $d['photo_x'] ?>"></label>
    <label class="grow">Focus down <input type="range" name="photo_y" min="0" max="100" value="<?= $d['photo_y'] ?>"></label>
  </div>
  <div class="row wrap" data-photo-cover>
    <label class="grow">Tint with the background colour <input type="range" name="shade" min="0" max="100" value="<?= $d['shade'] ?>"></label>
    <label class="check"><input type="checkbox" name="shade_fade" value="1" <?= $d['shade_fade'] ? 'checked' : '' ?>> Only on the text side</label>
  </div>
</fieldset>
