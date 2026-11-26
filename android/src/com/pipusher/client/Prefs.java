package com.pipusher.client;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;

import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;

/** Settings made in the app, and the agent's pipusher.json written from them. */
final class Prefs {
    static final String PLAYER_URL = "http://127.0.0.1:8090/";
    static final int CONTROL_PORT = 8091;

    /** Browser setting: the app's own fullscreen player, or the system's default browser. Anything else is a package. */
    static final String BUILTIN = "builtin";
    static final String DEFAULT_BROWSER = "default";

    final SharedPreferences sp;

    Prefs(Context c) {
        sp = c.getSharedPreferences("pipusher", Context.MODE_PRIVATE);
    }

    String server() { return sp.getString("server", ""); }
    String name() { return sp.getString("name", ""); }
    String key() { return sp.getString("key", ""); }
    boolean insecure() { return sp.getBoolean("insecure", false); }
    String browser() { return sp.getString("browser", BUILTIN); }
    boolean builtin() { return BUILTIN.equals(browser()); }
    String orientation() { return sp.getString("orientation", "landscape"); }
    boolean autostart() { return sp.getBoolean("autostart", true); }
    boolean home() { return sp.getBoolean("home", false); }
    String pin() { return sp.getString("pin", ""); }
    boolean configured() { return !server().isEmpty(); }
    /** The built-in player is on screen (not left for the settings or another app). */
    boolean showing() { return sp.getBoolean("showing", false); }
    void setShowing(boolean on) { sp.edit().putBoolean("showing", on).apply(); }

    static String defaultName(Context c) {
        String n = null;
        if (Build.VERSION.SDK_INT >= 25) n = Settings.Global.getString(c.getContentResolver(), Settings.Global.DEVICE_NAME);
        return n == null || n.trim().isEmpty() ? Build.MODEL : n.trim();
    }

    static File configFile(Context c) { return new File(c.getFilesDir(), "pipusher.json"); }
    static File logFile(Context c) { return new File(c.getFilesDir(), "pipusher.log"); }
    static File dnsFile(Context c) { return new File(c.getFilesDir(), "dns.txt"); }

    /** Writes the agent's configuration (read when the agent starts). */
    void writeConfig(Context c) throws Exception {
        JSONObject o = new JSONObject();
        o.put("server_url", server());
        o.put("registration_key", key());
        o.put("name", name().isEmpty() ? defaultName(c) : name());
        o.put("data_dir", new File(c.getFilesDir(), "data").getAbsolutePath());
        o.put("listen", "127.0.0.1:8090");
        o.put("insecure_tls", insecure());
        o.put("control_url", "http://127.0.0.1:" + CONTROL_PORT + "/");
        o.put("dns_file", dnsFile(c).getAbsolutePath());
        File f = configFile(c);
        File tmp = new File(f.getPath() + ".tmp");
        try (FileOutputStream out = new FileOutputStream(tmp)) {
            out.write(o.toString(2).getBytes("UTF-8"));
        }
        if (!tmp.renameTo(f)) throw new Exception("cannot write " + f);
    }

    /** Turns the HOME entry on or off ("Use as home app"). */
    void applyHome(Context c) {
        c.getPackageManager().setComponentEnabledSetting(new ComponentName(c, c.getPackageName() + ".HomeAlias"),
            home() ? PackageManager.COMPONENT_ENABLED_STATE_ENABLED : PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
            PackageManager.DONT_KILL_APP);
    }

    /** Shows the player: the app's fullscreen player, or the player page in the chosen browser. */
    void launchPlayer(Context c) {
        Intent i;
        if (builtin()) {
            i = new Intent(c, PlayerActivity.class);
        } else {
            i = new Intent(Intent.ACTION_VIEW, Uri.parse(PLAYER_URL));
            if (!DEFAULT_BROWSER.equals(browser())) i.setPackage(browser());
        }
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            c.startActivity(i);
        } catch (Exception e) {
            // The chosen browser was uninstalled: fall back to the built-in player.
            c.startActivity(new Intent(c, PlayerActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        }
    }
}
