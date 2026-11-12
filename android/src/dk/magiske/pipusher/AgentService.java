package dk.magiske.pipusher;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.net.ConnectivityManager;
import android.net.LinkProperties;
import android.net.Network;
import android.net.NetworkRequest;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.util.Log;
import android.text.TextUtils;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import org.json.JSONObject;

import java.io.ByteArrayInputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.net.URLDecoder;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.TimeZone;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;

/**
 * Keeps the PiPusher agent (the Go client, shipped as libpipusher.so) running in the background, keeps its DNS
 * servers up to date, and answers its control calls: screenshots of the player, player restarts and reboots.
 */
public class AgentService extends Service {
    static final String TAG = "PiPusher";
    static final String ACTION_RESTART = "dk.magiske.pipusher.RESTART"; // settings changed: restart the agent
    static final String EXTRA_LAUNCH = "launch";                        // also show the player (boot)

    private Process agent;
    private Thread runner;
    private volatile boolean stopping;
    private ServerSocket control;
    private ConnectivityManager.NetworkCallback netCallback;
    private PowerManager.WakeLock wake;

    static void start(Context c, boolean restart, boolean launch) {
        Intent i = new Intent(c, AgentService.class).putExtra(EXTRA_LAUNCH, launch);
        if (restart) i.setAction(ACTION_RESTART);
        if (Build.VERSION.SDK_INT >= 26) c.startForegroundService(i);
        else c.startService(i);
    }

    @Override
    public IBinder onBind(Intent intent) { return null; }

    @Override
    public void onCreate() {
        super.onCreate();
        goForeground();
        // Keeps the CPU awake for downloads and the agent while the screen is on or off.
        wake = ((PowerManager) getSystemService(POWER_SERVICE)).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "PiPusher:agent");
        wake.acquire();
        watchNetwork();
        startControl();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        goForeground();
        Prefs p = new Prefs(this);
        boolean restart = intent != null && ACTION_RESTART.equals(intent.getAction());
        try {
            p.writeConfig(this);
        } catch (Exception e) {
            Log.e(TAG, "config", e);
        }
        if (restart) killAgent();
        if (runner == null || !runner.isAlive()) {
            stopping = false;
            runner = new Thread(this::runAgent, "pipusher-agent");
            runner.start();
        }
        if (intent != null && intent.getBooleanExtra(EXTRA_LAUNCH, false) && p.configured()) p.launchPlayer(this);
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        stopping = true;
        killAgent();
        try {
            if (control != null) control.close();
        } catch (Exception ignored) {
        }
        if (netCallback != null) {
            try {
                ((ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE)).unregisterNetworkCallback(netCallback);
            } catch (Exception ignored) {
            }
        }
        if (wake != null && wake.isHeld()) wake.release();
        super.onDestroy();
    }

    private void goForeground() {
        Notification.Builder b;
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationChannel ch = new NotificationChannel("agent", "PiPusher", NotificationManager.IMPORTANCE_MIN);
            ((NotificationManager) getSystemService(NOTIFICATION_SERVICE)).createNotificationChannel(ch);
            b = new Notification.Builder(this, "agent");
        } else {
            b = new Notification.Builder(this).setPriority(Notification.PRIORITY_MIN);
        }
        Intent open = new Intent(this, SettingsActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        b.setContentTitle("PiPusher").setContentText("Player running. Tap for settings.")
            .setSmallIcon(android.R.drawable.ic_media_play).setOngoing(true)
            .setContentIntent(PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE));
        if (Build.VERSION.SDK_INT >= 34) startForeground(1, b.build(), ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        else startForeground(1, b.build());
    }

    // ------------------------------------------------------------------ the agent process

    private void runAgent() {
        File bin = new File(getApplicationInfo().nativeLibraryDir, "libpipusher.so");
        while (!stopping) {
            if (!new Prefs(this).configured()) {
                pause(5000);
                continue;
            }
            try {
                ProcessBuilder pb = new ProcessBuilder(bin.getAbsolutePath(),
                    "-config", Prefs.configFile(this).getAbsolutePath(), "-log", Prefs.logFile(this).getAbsolutePath());
                pb.environment().put("TZ", TimeZone.getDefault().getID());
                pb.environment().put("HOME", getFilesDir().getAbsolutePath());
                pb.environment().put("TMPDIR", getCacheDir().getAbsolutePath());
                pb.redirectErrorStream(true);
                pb.directory(getFilesDir());
                Process proc;
                synchronized (this) {
                    if (stopping) break;
                    proc = agent = pb.start();
                }
                InputStream out = proc.getInputStream(); // the log goes to the file; drain anything else
                byte[] buf = new byte[4096];
                while (out.read(buf) >= 0) {
                    // discard
                }
                int code = proc.waitFor();
                Log.w(TAG, "agent exited with " + code);
            } catch (Exception e) {
                Log.e(TAG, "agent", e);
            }
            synchronized (this) {
                agent = null;
            }
            pause(3000); // then start it again (crash, settings change)
        }
    }

    private synchronized void killAgent() {
        if (agent != null) agent.destroy();
    }

    private static void pause(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException ignored) {
        }
    }

    // ------------------------------------------------------------------ DNS servers for the agent

    private void watchNetwork() {
        final ConnectivityManager cm = (ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE);
        writeDns(cm);
        netCallback = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(Network n) { writeDns(cm); }

            @Override
            public void onLinkPropertiesChanged(Network n, LinkProperties lp) { writeDns(cm); }

            @Override
            public void onLost(Network n) { writeDns(cm); }
        };
        try {
            if (Build.VERSION.SDK_INT >= 24) cm.registerDefaultNetworkCallback(netCallback);
            else cm.registerNetworkCallback(new NetworkRequest.Builder().build(), netCallback);
        } catch (Exception e) {
            Log.e(TAG, "network callback", e);
        }
    }

    private void writeDns(ConnectivityManager cm) {
        List<Network> nets = new ArrayList<>();
        if (Build.VERSION.SDK_INT >= 23 && cm.getActiveNetwork() != null) nets.add(cm.getActiveNetwork());
        for (Network n : cm.getAllNetworks()) if (!nets.contains(n)) nets.add(n);
        StringBuilder sb = new StringBuilder();
        for (Network n : nets) {
            LinkProperties lp = cm.getLinkProperties(n);
            if (lp == null) continue;
            for (InetAddress a : lp.getDnsServers()) sb.append(a.getHostAddress()).append('\n');
        }
        try (FileOutputStream f = new FileOutputStream(Prefs.dnsFile(this))) {
            f.write(sb.toString().getBytes("UTF-8"));
        } catch (Exception e) {
            Log.e(TAG, "dns", e);
        }
    }

    // ------------------------------------------------------------------ control calls from the agent

    private void startControl() {
        try {
            control = new ServerSocket(Prefs.CONTROL_PORT, 8, InetAddress.getByName("127.0.0.1"));
        } catch (Exception e) {
            Log.e(TAG, "control port", e);
            return;
        }
        Thread t = new Thread(() -> {
            while (!control.isClosed()) {
                try {
                    final Socket s = control.accept();
                    new Thread(() -> handle(s), "pipusher-control").start();
                } catch (Exception e) {
                    if (!control.isClosed()) pause(500);
                }
            }
        }, "pipusher-control-accept");
        t.setDaemon(true);
        t.start();
    }

    private void handle(Socket s) {
        try (Socket sock = s) {
            sock.setSoTimeout(30000);
            InputStream in = sock.getInputStream();
            String line = readLine(in);
            while (true) { // skip the headers; the calls carry no body
                String h = readLine(in);
                if (h == null || h.isEmpty()) break;
            }
            String[] parts = line == null ? new String[0] : line.split(" ");
            String target = parts.length > 1 ? parts[1] : "/";
            String path = target.contains("?") ? target.substring(0, target.indexOf('?')) : target;
            String query = target.contains("?") ? target.substring(target.indexOf('?') + 1) : "";
            int code = 200;
            String type = "text/plain; charset=utf-8";
            byte[] body;
            try {
                switch (path) {
                    case "/capture":
                        body = PlayerActivity.capture(query);
                        type = "image/jpeg";
                        break;
                    case "/restart":
                        body = restartPlayer().getBytes("UTF-8");
                        break;
                    case "/reboot":
                        body = reboot().getBytes("UTF-8");
                        break;
                    case "/cookies":
                        body = setCookies(query).getBytes("UTF-8");
                        break;
                    case "/storage":
                        body = setStorage(query).getBytes("UTF-8");
                        break;
                    default:
                        code = 404;
                        body = "not found".getBytes("UTF-8");
                }
            } catch (Exception e) {
                code = 503;
                body = String.valueOf(e.getMessage()).getBytes("UTF-8");
            }
            OutputStream out = sock.getOutputStream();
            out.write(("HTTP/1.1 " + code + (code == 200 ? " OK" : " Error") + "\r\nContent-Type: " + type
                + "\r\nContent-Length: " + body.length + "\r\nConnection: close\r\n\r\n").getBytes("UTF-8"));
            out.write(body);
            out.flush();
        } catch (Exception e) {
            Log.w(TAG, "control", e);
        }
    }

    private static String readLine(InputStream in) throws Exception {
        StringBuilder sb = new StringBuilder();
        int c;
        while ((c = in.read()) >= 0 && c != '\n') if (c != '\r') sb.append((char) c);
        return c < 0 && sb.length() == 0 ? null : sb.toString();
    }

    /**
     * Cookies for a web page from the agent (url=<page>&set=<Set-Cookie lines>), e.g. an accepted cookie banner, so the
     * page shows without it. They go into the built-in player's cookie store; another browser keeps its own.
     */
    private String setCookies(String query) throws Exception {
        String page = null, set = "";
        for (String kv : query.split("&")) {
            int eq = kv.indexOf('=');
            if (eq < 0) continue;
            String k = kv.substring(0, eq), v = URLDecoder.decode(kv.substring(eq + 1), "UTF-8");
            if (k.equals("url")) page = v;
            else if (k.equals("set")) set = v;
        }
        if (page == null) throw new Exception("url missing");
        final String url = page;
        final String[] lines = set.split("\n");
        final CountDownLatch done = new CountDownLatch(1);
        new Handler(Looper.getMainLooper()).post(() -> {
            CookieManager cm = CookieManager.getInstance();
            cm.setAcceptCookie(true);
            for (String line : lines) if (!line.trim().isEmpty()) cm.setCookie(url, line);
            cm.flush();
            done.countDown();
        });
        if (!done.await(10, TimeUnit.SECONDS)) throw new Exception("cookie store did not answer");
        return lines.length + " cookie(s) set for " + url;
    }

    /**
     * Local storage entries for web pages from the agent (pages=<JSON {url: page}>), for sites that keep an accepted
     * cookie banner there. A hidden WebView with the player's origin loads each url in an iframe and answers it itself
     * with the page, which writes the entries; so they land where the site finds them inside a layout zone.
     */
    private String setStorage(String query) throws Exception {
        String pages = null;
        for (String kv : query.split("&")) {
            int eq = kv.indexOf('=');
            if (eq >= 0 && kv.substring(0, eq).equals("pages")) pages = URLDecoder.decode(kv.substring(eq + 1), "UTF-8");
        }
        if (pages == null) throw new Exception("pages missing");
        final JSONObject serve = new JSONObject(pages);
        StringBuilder host = new StringBuilder("<!doctype html><script>var left=" + serve.length()
            + ";function loaded(){if(--left===0)PP.done()}</script>");
        for (Iterator<String> it = serve.keys(); it.hasNext(); ) {
            host.append("<iframe onload=\"loaded()\" src=\"").append(TextUtils.htmlEncode(it.next())).append("\"></iframe>");
        }
        final String hostPage = host.toString();
        final CountDownLatch written = new CountDownLatch(1);
        final WebView[] view = new WebView[1];
        Handler main = new Handler(Looper.getMainLooper());
        main.post(() -> {
            WebView w = new WebView(this);
            view[0] = w;
            w.getSettings().setJavaScriptEnabled(true);
            w.getSettings().setDomStorageEnabled(true);
            w.addJavascriptInterface(new Object() {
                @JavascriptInterface
                public void done() {
                    written.countDown();
                }
            }, "PP");
            w.setWebViewClient(new WebViewClient() {
                @Override
                public WebResourceResponse shouldInterceptRequest(WebView v, WebResourceRequest r) {
                    String u = r.getUrl().toString();
                    int cut = u.indexOf('?');
                    String page = serve.optString(cut < 0 ? u : u.substring(0, cut), null);
                    if (page == null) return null;
                    try {
                        return new WebResourceResponse("text/html", "UTF-8", new ByteArrayInputStream(page.getBytes("UTF-8")));
                    } catch (Exception e) {
                        return null;
                    }
                }
            });
            w.loadDataWithBaseURL(Prefs.PLAYER_URL, hostPage, "text/html", "UTF-8", null);
        });
        boolean ok = written.await(20, TimeUnit.SECONDS);
        main.post(() -> {
            if (view[0] != null) view[0].destroy();
        });
        if (!ok) throw new Exception("local storage pages did not load");
        return serve.length() + " local storage page(s) written";
    }

    /** The agent found the player stuck: a fresh WebView, or the player opened again in the browser. */
    private String restartPlayer() {
        if (SettingsActivity.visible) return "settings are open, player left alone";
        Prefs p = new Prefs(this);
        if (p.builtin() && PlayerActivity.restartWebView()) return "player restarted";
        p.launchPlayer(this);
        return "player opened";
    }

    /** Reboots when the device allows it (rooted), otherwise restarts the agent and the player. */
    private String reboot() {
        try {
            Process su = new ProcessBuilder("su", "-c", "reboot").redirectErrorStream(true).start();
            if (su.waitFor() == 0) return "rebooting";
        } catch (Exception ignored) {
        }
        new Thread(() -> {
            pause(1000);
            killAgent(); // started again by runAgent
            restartPlayer();
        }).start();
        return "this device does not allow apps to reboot it; restarting the player instead";
    }
}
