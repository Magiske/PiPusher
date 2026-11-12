package dk.magiske.pipusher;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ResolveInfo;
import android.graphics.Insets;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.WindowInsets;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.RandomAccessFile;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.List;

/** Everything a screen needs, set in the app: server, name, key, browser, start on boot, orientation, PIN. */
public class SettingsActivity extends Activity {
    static volatile boolean visible; // the agent's player restarts leave the screen alone while this is open

    private final Handler ui = new Handler(Looper.getMainLooper());
    private Prefs prefs;
    private EditText server, name, key, pin;
    private CheckBox insecure, autostart, home;
    private Spinner browser, orientation;
    private final List<String> browserIds = new ArrayList<>();
    private TextView status, overlayNote, batteryNote;
    private Button overlayBtn, batteryBtn;
    private final String[] orientIds = {"landscape", "portrait", "reverse_landscape", "reverse_portrait", "auto"};

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        prefs = new Prefs(this);
        int pad = dp(20);
        LinearLayout col = new LinearLayout(this);
        col.setOrientation(LinearLayout.VERTICAL);
        col.setPadding(pad, pad, pad, pad);

        TextView title = text(col, "PiPusher " + version() + " (experimental)", 24);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        status = text(col, "", 15);
        status.setPadding(0, dp(6), 0, dp(12));

        section(col, "Server");
        server = field(col, "Server address", "https://example.com/pipusher/", prefs.server(), InputType.TYPE_TEXT_VARIATION_URI);
        name = field(col, "Screen name", Prefs.defaultName(this), prefs.name(), InputType.TYPE_TEXT_FLAG_CAP_WORDS);
        key = field(col, "Registration key (optional: approves the screen at once)", "", prefs.key(), InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD);
        insecure = check(col, "Accept a self-signed server certificate", prefs.insecure());

        section(col, "Player");
        label(col, "Browser");
        browser = new Spinner(this);
        List<String> labels = new ArrayList<>();
        browserIds.add(Prefs.BUILTIN);
        labels.add("Built-in fullscreen player (recommended)");
        browserIds.add(Prefs.DEFAULT_BROWSER);
        labels.add("System default browser");
        for (ResolveInfo ri : browsers()) {
            if (browserIds.contains(ri.activityInfo.packageName)) continue;
            browserIds.add(ri.activityInfo.packageName);
            labels.add(String.valueOf(ri.loadLabel(getPackageManager())));
        }
        browser.setAdapter(new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, labels));
        browser.setSelection(Math.max(0, browserIds.indexOf(prefs.browser())));
        col.addView(browser);
        note(col, "Another browser shows the player with its own address bar, may not play video sound on its own, and "
            + "cannot send screenshots. The built-in player is fullscreen and is restarted when it stops.");

        label(col, "Screen orientation");
        orientation = new Spinner(this);
        orientation.setAdapter(new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item,
            new String[] {"Landscape", "Portrait", "Landscape, upside down", "Portrait, upside down", "Follow the device"}));
        for (int i = 0; i < orientIds.length; i++) if (orientIds[i].equals(prefs.orientation())) orientation.setSelection(i);
        col.addView(orientation);
        note(col, "Portrait layouts are turned by the player itself, so a landscape TV can stay on Landscape.");

        section(col, "Start");
        autostart = check(col, "Start the player when the device starts", prefs.autostart());
        overlayNote = note(col, "");
        overlayBtn = button(col, "Allow starting on boot", v -> {
            if (Build.VERSION.SDK_INT >= 23) {
                try {
                    startActivity(new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:" + getPackageName())));
                } catch (Exception e) {
                    Toast.makeText(this, "Not available on this device: see the note above.", Toast.LENGTH_LONG).show();
                }
            }
        });
        home = check(col, "Use as home app (Home and start-up always return to the player)", prefs.home());
        batteryNote = note(col, "");
        batteryBtn = button(col, "Keep running in the background", v -> {
            try {
                startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName())));
            } catch (Exception e) {
                Toast.makeText(this, "Not available on this device.", Toast.LENGTH_LONG).show();
            }
        });

        section(col, "Settings lock");
        pin = field(col, "PIN for these settings (empty = none)", "", prefs.pin(),
            InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        pin.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        note(col, "From the player, open these settings with 5 taps in the top-left corner, 5 presses of OK on a remote, "
            + "Menu, or by holding Back.");

        LinearLayout row = new LinearLayout(this);
        row.setPadding(0, dp(16), 0, 0);
        row.setGravity(Gravity.CENTER_VERTICAL);
        col.addView(row);
        Button save = button(row, "Save and start player", v -> save());
        button(row, "Show log", v -> showLog());
        button(row, "Stop player", v -> {
            stopService(new Intent(this, AgentService.class));
            finishAffinity();
        });

        ScrollView sv = new ScrollView(this);
        sv.addView(col);
        if (Build.VERSION.SDK_INT >= 35) {
            // Android 15+ lays the page out under the status and navigation bars (and the keyboard): keep it clear of them.
            sv.setOnApplyWindowInsetsListener((v, in) -> {
                Insets i = in.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout() | WindowInsets.Type.ime());
                v.setPadding(i.left, i.top, i.right, i.bottom);
                return WindowInsets.CONSUMED;
            });
        }
        setContentView(sv);
        (prefs.configured() ? save : server).requestFocus();
    }

    @Override
    protected void onResume() {
        super.onResume();
        visible = true;
        refreshPermissions();
        ui.post(poll);
    }

    @Override
    protected void onPause() {
        visible = false;
        ui.removeCallbacks(poll);
        super.onPause();
    }

    private void refreshPermissions() {
        boolean need = Build.VERSION.SDK_INT >= 29;
        boolean ok = Settings.canDrawOverlays(this);
        overlayNote.setText(!need ? "This Android version opens the player at start-up without extra permission."
            : ok ? "Starting on boot is allowed (\"Display over other apps\" is on)."
            : "Android 10 and newer only open the player at start-up when PiPusher may \"Display over other apps\". "
                + "On TV boxes without that screen: adb shell appops set " + getPackageName() + " SYSTEM_ALERT_WINDOW allow");
        overlayBtn.setVisibility(need && !ok ? View.VISIBLE : View.GONE);
        boolean free = ((PowerManager) getSystemService(POWER_SERVICE)).isIgnoringBatteryOptimizations(getPackageName());
        batteryNote.setText(free ? "" : "Battery saving may stop the player in the background on phones and tablets.");
        batteryNote.setVisibility(free ? View.GONE : View.VISIBLE);
        batteryBtn.setVisibility(free ? View.GONE : View.VISIBLE);
    }

    private void save() {
        String s = server.getText().toString().trim();
        if (s.isEmpty()) {
            server.setError("The PiPusher server address, like https://example.com/pipusher/");
            server.requestFocus();
            return;
        }
        prefs.sp.edit()
            .putString("server", s)
            .putString("name", name.getText().toString().trim())
            .putString("key", key.getText().toString().trim())
            .putBoolean("insecure", insecure.isChecked())
            .putString("browser", browserIds.get(browser.getSelectedItemPosition()))
            .putString("orientation", orientIds[orientation.getSelectedItemPosition()])
            .putBoolean("autostart", autostart.isChecked())
            .putBoolean("home", home.isChecked())
            .putString("pin", pin.getText().toString().trim())
            .apply();
        prefs.applyHome(this);
        AgentService.start(this, true, false);
        prefs.launchPlayer(this);
        finish();
    }

    /** The agent's state (approval code, connection), refreshed every 2 s. */
    private final Runnable poll = new Runnable() {
        @Override
        public void run() {
            new Thread(() -> {
                String line;
                try {
                    HttpURLConnection c = (HttpURLConnection) new URL(Prefs.PLAYER_URL + "api/state").openConnection();
                    c.setConnectTimeout(1500);
                    c.setReadTimeout(1500);
                    JSONObject o = new JSONObject(read(c.getInputStream()));
                    String st = o.optString("state");
                    String code = o.optString("code");
                    switch (st) {
                        case "pending": line = "Waiting for approval in the admin panel. Device code: " + code; break;
                        case "ready": line = "Playing" + (o.optBoolean("online") ? "" : " (offline, from the cache)"); break;
                        case "nolayout": line = "Approved. Assign a layout or playlist to this screen in the admin panel."; break;
                        case "removed": line = "This screen was removed on the server."; break;
                        default: line = o.optString("message", "Connecting to the server…");
                    }
                    if (!o.optString("name").isEmpty()) line += "\nScreen: " + o.optString("name");
                } catch (Exception e) {
                    line = prefs.configured() ? "Starting the player…" : "Enter the server address and save to start.";
                }
                final String text = line;
                ui.post(() -> status.setText(text));
            }).start();
            ui.postDelayed(this, 2000);
        }
    };

    private void showLog() {
        String text;
        try (RandomAccessFile f = new RandomAccessFile(Prefs.logFile(this), "r")) {
            long len = f.length();
            long from = Math.max(0, len - 16000);
            f.seek(from);
            byte[] buf = new byte[(int) (len - from)];
            f.readFully(buf);
            text = new String(buf, "UTF-8");
        } catch (Exception e) {
            text = "No log yet.";
        }
        TextView tv = new TextView(this);
        tv.setText(text);
        tv.setTextSize(TypedValue.COMPLEX_UNIT_SP, 11);
        tv.setTypeface(Typeface.MONOSPACE);
        tv.setPadding(dp(12), dp(12), dp(12), dp(12));
        tv.setTextIsSelectable(true);
        final ScrollView sv = new ScrollView(this);
        sv.addView(tv);
        sv.post(() -> sv.fullScroll(View.FOCUS_DOWN));
        new AlertDialog.Builder(this).setTitle("Log").setView(sv).setPositiveButton("Close", null).show();
    }

    private List<ResolveInfo> browsers() {
        Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse("http://example.com/"));
        i.addCategory(Intent.CATEGORY_BROWSABLE);
        int flags = PackageManager.MATCH_ALL;
        List<ResolveInfo> out = new ArrayList<>();
        for (ResolveInfo ri : getPackageManager().queryIntentActivities(i, flags)) {
            if (!ri.activityInfo.packageName.equals(getPackageName())) out.add(ri);
        }
        return out;
    }

    private String version() {
        try {
            return getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
        } catch (Exception e) {
            return "";
        }
    }

    static String read(InputStream in) throws Exception {
        try (InputStream s = in) {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buf = new byte[8192];
            int n;
            while ((n = s.read(buf)) > 0) out.write(buf, 0, n);
            return out.toString("UTF-8");
        }
    }

    // ------------------------------------------------------------------ small view helpers

    private int dp(int v) { return Math.round(v * getResources().getDisplayMetrics().density); }

    private TextView text(LinearLayout parent, String s, int sp) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        parent.addView(t);
        return t;
    }

    private void section(LinearLayout parent, String s) {
        TextView t = text(parent, s, 18);
        t.setTypeface(Typeface.DEFAULT_BOLD);
        t.setPadding(0, dp(18), 0, dp(4));
    }

    private void label(LinearLayout parent, String s) {
        text(parent, s, 14).setPadding(0, dp(8), 0, 0);
    }

    private TextView note(LinearLayout parent, String s) {
        TextView t = text(parent, s, 13);
        t.setAlpha(0.7f);
        t.setPadding(0, dp(2), 0, dp(6));
        return t;
    }

    private EditText field(LinearLayout parent, String label, String hint, String value, int type) {
        label(parent, label);
        EditText e = new EditText(this);
        e.setSingleLine(true);
        e.setInputType(InputType.TYPE_CLASS_TEXT | type);
        e.setHint(hint);
        e.setText(value);
        parent.addView(e);
        return e;
    }

    private CheckBox check(LinearLayout parent, String label, boolean on) {
        CheckBox c = new CheckBox(this);
        c.setText(label);
        c.setChecked(on);
        c.setPadding(c.getPaddingLeft(), dp(8), 0, dp(8));
        parent.addView(c);
        return c;
    }

    private Button button(LinearLayout parent, String label, View.OnClickListener l) {
        Button btn = new Button(this);
        btn.setText(label);
        btn.setOnClickListener(l);
        btn.setAllCaps(false);
        parent.addView(btn);
        return btn;
    }
}
