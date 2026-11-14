<?php
/** The ticker editor's form fields, inside media_edit.php's form ($m = the ticker media item). */
$d = ticker_media_design($m);
$opts = function (array $choices, string $current): string {
    $out = '';
    foreach ($choices as $k => $label) {
        $out .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
};
$fonts = text_font_choices();
if (!isset($fonts[$d['font']])) {
    $fonts[$d['font']] = 'Removed font (kept until you choose another)';
}
?>
<fieldset class="text-group">
  <legend>Messages</legend>
  <textarea name="messages" rows="5" placeholder="One message per line"><?= h(implode("\n", $d['messages'])) ?></textarea>
  <p class="muted small">One message per line. They scroll past one after another, with the separator between them, and start over.</p>
  <div class="row wrap align-end">
    <label>Separator <select name="separator"><?= $opts(PP_TICKER_SEPARATORS, $d['separator']) ?></select></label>
    <label>Label <input name="label" value="<?= h($d['label']) ?>" maxlength="40" placeholder="Optional, e.g. NEWS"></label>
  </div>
  <p class="muted small">The label stays at the left end of the ticker while the messages scroll past it.</p>
</fieldset>

<fieldset class="text-group">
  <legend>Look</legend>
  <div class="row wrap align-end">
    <label>Font <select name="font"><?= $opts($fonts, $d['font']) ?></select></label>
    <label>Text size <select name="size"><?= $opts(PP_TICKER_SIZES, (string)(float)$d['size']) ?></select></label>
    <label class="check"><input type="checkbox" name="bold" value="1" <?= $d['bold'] ? 'checked' : '' ?>> Bold</label>
  </div>
  <div class="row wrap align-end">
    <label>Text <input type="color" name="color" value="<?= h($d['color']) ?>"></label>
    <label>Background <input type="color" name="background" value="<?= h($d['background']) ?>"></label>
    <label title="Separator and label background">Accent <input type="color" name="accent" value="<?= h($d['accent']) ?>"></label>
    <label class="check"><input type="checkbox" name="transparent" value="1" <?= $d['transparent'] ? 'checked' : '' ?>> No background (show what is under the zone)</label>
  </div>
  <p class="muted small"><a href="<?= url(['p' => 'media', 'type' => 'text']) ?>#fonts">Add your own fonts</a>.</p>
</fieldset>

<fieldset class="text-group">
  <legend>Movement</legend>
  <div class="row wrap align-end">
    <label>Speed <select name="speed"><?= $opts(PP_TICKER_SPEEDS, (string)(float)$d['speed']) ?></select></label>
    <label>Direction <select name="direction"><?= $opts(['left' => 'Right to left ←', 'right' => 'Left to right →'], $d['direction']) ?></select></label>
  </div>
  <p class="muted small">The speed follows the text size, so the ticker looks the same on every screen. Give the
    ticker a long duration in the playlist (or make it the zone's only item): it then keeps scrolling without restarting.</p>
</fieldset>
