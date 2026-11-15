<?php
/** The colour editor's form fields, inside media_edit.php's form ($m = the colour media item). */
$d = color_media_design($m);
?>
<fieldset class="text-group">
  <legend>Colour</legend>
  <div class="row wrap align-end">
    <label>Colour <input type="color" name="color" value="<?= h($d['color']) ?>"></label>
    <label title="100 = solid; lower lets what is under the zone show through">Opacity
      <input type="number" name="opacity" value="<?= $d['opacity'] ?>" min="0" max="100" step="5" class="num"> %</label>
  </div>
  <div class="row wrap align-end">
    <label>Fade <select name="fade"><?php foreach (PP_COLOR_FADES as $k => $label): ?>
      <option value="<?= h($k) ?>"<?= $k === $d['fade'] ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <label title="How much of the zone the fade covers; the rest has the full colour">Over
      <input type="number" name="reach" value="<?= $d['reach'] ?>" min="5" max="100" step="5" class="num"> % of the zone</label>
  </div>
  <p class="muted small">Use it <b>at the bottom</b> of a layout as a coloured background for text, a clock or a
    ticker, or <b>on top</b> (a higher layer in the zone table) to tint a photo or video: a lower opacity lets the
    picture show through, and a fade lets the colour run out towards an edge, so the picture blends into a colour
    behind text. Opacity and fade work together.</p>
</fieldset>
