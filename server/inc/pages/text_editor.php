<?php
/** The text editor's form fields, inside media_edit.php's form ($m = the text media item). */
$d = text_media_design($m);
$opts = function (array $choices, string $current): string {
    $out = '';
    foreach ($choices as $k => $label) {
        $out .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
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
$fonts = text_font_choices();
if (!isset($fonts[$d['font']])) {
    $fonts[$d['font']] = 'Removed font (kept until you choose another)';
}
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
  <div class="row wrap align-end">
    <label>Font <select name="font"><?= $opts($fonts, $d['font']) ?></select></label>
    <label>Across <select name="align"><?= $opts(['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'], $d['align']) ?></select></label>
    <label>Down <select name="valign"><?= $opts(['top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom'], $d['valign']) ?></select></label>
  </div>
  <label class="check"><input type="checkbox" name="shadow" value="1" <?= $d['shadow'] ? 'checked' : '' ?>> Shadow under the text</label>
  <p class="muted small">The text has no background: place its zone on top of another zone in the layout, or set the
    layout's background colour. <a href="<?= url(['p' => 'media', 'type' => 'text']) ?>#fonts">Add your own fonts</a>.</p>
</fieldset>
