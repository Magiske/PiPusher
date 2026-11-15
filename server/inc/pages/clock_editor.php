<?php
/** The clock editor's form fields, inside media_edit.php's form ($m = the clock media item). */
$d = clock_media_design($m);
$opts = function (array $choices, string $current): string {
    $out = '';
    foreach ($choices as $k => $label) {
        $out .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
};
$check = fn(string $name, string $label) => '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($d[$name] ? ' checked' : '') . '> ' . h($label) . '</label>';
$fonts = text_font_choices();
if (!isset($fonts[$d['font']])) {
    $fonts[$d['font']] = 'Removed font (kept until you choose another)';
}
?>
<fieldset class="text-group">
  <legend>Show</legend>
  <div class="row wrap align-end">
    <?= $check('time', 'Time') ?>
    <?= $check('seconds', 'Seconds') ?>
    <?= $check('hour12', '12-hour clock (AM/PM)') ?>
    <?= $check('date', 'Date') ?>
  </div>
  <div class="row wrap align-end">
    <label>Date as <select name="date_style"><?= $opts(PP_CLOCK_DATES, $d['date_style']) ?></select></label>
    <label>Language <input name="locale" value="<?= h($d['locale']) ?>" size="8" placeholder="Screen's" title="For example en-GB, nb-NO, de-DE, sv-SE"></label>
  </div>
  <p class="muted small">The time is the screen's own clock. Leave Language empty to use the screen's language, or
    enter a code such as <code>en-GB</code>, <code>nb-NO</code> or <code>de-DE</code>.</p>
</fieldset>

<fieldset class="text-group">
  <legend>Weather</legend>
  <?= $check('weather', 'Show the weather') ?>
  <div class="row wrap align-end">
    <label>Forecast <select name="forecast"><?= $opts(PP_CLOCK_FORECAST, (string)$d['forecast']) ?></select></label>
    <label>Units <select name="units"><?= $opts(['c' => '°C', 'f' => '°F'], $d['units']) ?></select></label>
  </div>
  <div class="row wrap align-end">
    <label>Place <input name="place" id="clock-place" value="<?= h($d['place']) ?>" placeholder="Town or city"></label>
    <button type="button" class="btn" id="clock-find">Find</button>
    <label>Latitude <input name="lat" id="clock-lat" value="<?= h((string)$d['lat']) ?>" size="8" inputmode="decimal"></label>
    <label>Longitude <input name="lon" id="clock-lon" value="<?= h((string)$d['lon']) ?>" size="8" inputmode="decimal"></label>
  </div>
  <p class="muted small" id="clock-found">Type a place and press Find to fill in its position, or enter the position
    yourself. The place name is shown next to the temperature (empty = no name). Weather from
    <a href="https://open-meteo.com" target="_blank" rel="noopener">open-meteo.com</a>, every 15 minutes; when a screen
    is offline it keeps the last weather for a day.</p>
</fieldset>

<fieldset class="text-group">
  <legend>Look</legend>
  <div class="row wrap align-end">
    <label>Arrange <select name="arrange"><?= $opts(PP_CLOCK_ARRANGE, $d['arrange']) ?></select></label>
    <label>Align <select name="align"><?= $opts(['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'], $d['align']) ?></select></label>
    <label>Size <select name="size"><?= $opts(PP_CLOCK_SIZES, (string)(float)$d['size']) ?></select></label>
  </div>
  <div class="row wrap align-end">
    <label>Font <select name="font"><?= $opts($fonts, $d['font']) ?></select></label>
    <label>Text <input type="color" name="color" value="<?= h($d['color']) ?>"></label>
    <label title="Weather icons and temperatures">Accent <input type="color" name="accent" value="<?= h($d['accent']) ?>"></label>
    <label>Background <input type="color" name="background" value="<?= h($d['background']) ?>"></label>
    <?= $check('transparent', 'No background (show what is under the zone)') ?>
  </div>
  <p class="muted small">Everything grows or shrinks to fit the zone, so the same item works in a corner, a sidebar, a
    footer or on the whole screen. <a href="<?= url(['p' => 'media', 'type' => 'text']) ?>#fonts">Add your own fonts</a>.</p>
</fieldset>
