package com.pipusher.client;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.pm.ActivityInfo;
import android.content.res.Configuration;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Point;
import android.graphics.Rect;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.text.InputType;
import android.view.KeyEvent;
import android.view.MotionEvent;
import android.view.PixelCopy;
import android.view.View;
import android.view.WindowManager;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.EditText;
import android.widget.FrameLayout;

import org.json.JSONArray;

import java.io.ByteArrayOutputStream;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;

/**
 * The built-in player: the agent's player page in a fullscreen WebView. The settings open after 5 taps in the
 * top-left corner, 5 presses of OK on a remote, Menu, or holding Back (then the PIN, when one is set).
 */
public class PlayerActivity extends Activity {
    private static volatile PlayerActivity current;
    private static final Handler ui = new Handler(Looper.getMainLooper());

    private FrameLayout root;
    private WebView web;
    private int taps;
    private long firstTap;
    private int layoutWidth; // screen width in pixels the page is laid out for

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON | WindowManager.LayoutParams.FLAG_FULLSCREEN
            | WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            | WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
        if (Build.VERSION.SDK_INT >= 27) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
        }
        root = new FrameLayout(this);
        root.setBackgroundColor(Color.BLACK);
        setContentView(root);
        newWebView();
        current = this;
        AgentService.start(this, false, false);
    }

    @Override
    protected void onResume() {
        super.onResume();
        current = this;
        setRequestedOrientation(orientation(new Prefs(this).orientation()));
        immersive();
    }

    @Override
    protected void onStart() {
        super.onStart();
        new Prefs(this).setShowing(true);
    }

    @Override
    protected void onStop() {
        if (!isChangingConfigurations()) new Prefs(this).setShowing(false); // left for the settings or another app
        super.onStop();
    }

    @Override
    public void onConfigurationChanged(Configuration c) {
        super.onConfigurationChanged(c);
        // Turned (orientation "follow the device", or the requested orientation taking effect): lay out again.
        if (web != null && screenWidth() != layoutWidth) web.loadUrl(playerUrl());
    }

    private int screenWidth() {
        Point p = new Point();
        getWindowManager().getDefaultDisplay().getRealSize(p);
        return p.x;
    }

    /**
     * The player page, laid out in screen pixels (?vw=) like on a Pi: Android otherwise uses its larger units, and a TV
     * at double density shows web pages in zones at twice their size.
     */
    private String playerUrl() {
        layoutWidth = screenWidth();
        return Prefs.PLAYER_URL + "?vw=" + layoutWidth;
    }

    @Override
    protected void onDestroy() {
        if (current == this) current = null;
        if (web != null) web.destroy();
        super.onDestroy();
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) immersive();
    }

    static int orientation(String o) {
        switch (o) {
            case "portrait": return ActivityInfo.SCREEN_ORIENTATION_PORTRAIT;
            case "reverse_landscape": return ActivityInfo.SCREEN_ORIENTATION_REVERSE_LANDSCAPE;
            case "reverse_portrait": return ActivityInfo.SCREEN_ORIENTATION_REVERSE_PORTRAIT;
            case "auto": return ActivityInfo.SCREEN_ORIENTATION_FULL_SENSOR;
            default: return ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE;
        }
    }

    @SuppressWarnings("deprecation")
    private void immersive() {
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
            | View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
            | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN);
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void newWebView() {
        if (web != null) {
            root.removeView(web);
            web.destroy();
        }
        web = new WebView(this);
        web.setBackgroundColor(Color.BLACK);
        web.setFocusable(false); // keys go to the activity (settings shortcuts), not the page
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false); // videos with sound start on their own
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        s.setCacheMode(WebSettings.LOAD_NO_CACHE);
        s.setUseWideViewPort(true);       // the page's viewport width (screen pixels, see playerUrl)...
        s.setLoadWithOverviewMode(true);  // ...scaled to fill the screen
        s.setSupportZoom(false);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedError(WebView v, WebResourceRequest r, WebResourceError e) {
                // The agent is not up yet (first start, after a crash): try again shortly.
                if (r.isForMainFrame()) ui.postDelayed(() -> { if (v == web) v.loadUrl(playerUrl()); }, 3000);
            }

            @Override
            public boolean onRenderProcessGone(WebView v, RenderProcessGoneDetail d) {
                if (v == web) {
                    web = null;
                    root.removeView(v);
                    v.destroy();
                    ui.post(PlayerActivity.this::newWebView);
                }
                return true;
            }
        });
        root.addView(web, new FrameLayout.LayoutParams(FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));
        web.loadUrl(playerUrl());
    }

    /** A fresh WebView in the running player. False when the player is not open. */
    static boolean restartWebView() {
        final PlayerActivity a = current;
        if (a == null || a.isFinishing()) return false;
        ui.post(a::newWebView);
        return true;
    }

    // ------------------------------------------------------------------ way out to the settings

    @Override
    public boolean dispatchTouchEvent(MotionEvent e) {
        if (e.getActionMasked() == MotionEvent.ACTION_DOWN) {
            View d = getWindow().getDecorView();
            if (e.getX() < d.getWidth() * 0.15f && e.getY() < d.getHeight() * 0.15f) countTap();
            else taps = 0;
        }
        return super.dispatchTouchEvent(e);
    }

    private void countTap() {
        long now = SystemClock.uptimeMillis();
        if (taps == 0 || now - firstTap > 3000) {
            taps = 0;
            firstTap = now;
        }
        if (++taps >= 5) {
            taps = 0;
            askSettings();
        }
    }

    @Override
    public boolean onKeyDown(int code, KeyEvent e) {
        switch (code) {
            case KeyEvent.KEYCODE_BACK:
                e.startTracking(); // a long press opens the settings; a short one does nothing
                return true;
            case KeyEvent.KEYCODE_MENU:
            case KeyEvent.KEYCODE_SETTINGS:
                askSettings();
                return true;
            case KeyEvent.KEYCODE_DPAD_CENTER:
            case KeyEvent.KEYCODE_ENTER:
            case KeyEvent.KEYCODE_NUMPAD_ENTER:
                if (e.getRepeatCount() == 0) countTap();
                return true;
        }
        return super.onKeyDown(code, e);
    }

    @Override
    public boolean onKeyLongPress(int code, KeyEvent e) {
        if (code == KeyEvent.KEYCODE_BACK) {
            askSettings();
            return true;
        }
        return super.onKeyLongPress(code, e);
    }

    @Override
    public boolean onKeyUp(int code, KeyEvent e) {
        if (code == KeyEvent.KEYCODE_BACK) return true;
        return super.onKeyUp(code, e);
    }

    @Override
    @SuppressWarnings("deprecation")
    public void onBackPressed() {
        // Kiosk: Back does not leave the player.
    }

    private void askSettings() {
        final String pin = new Prefs(this).pin();
        if (pin.isEmpty()) {
            openSettings();
            return;
        }
        final EditText in = new EditText(this);
        in.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        in.setHint("PIN");
        final AlertDialog d = new AlertDialog.Builder(this).setTitle("PiPusher settings").setView(in)
            .setPositiveButton("Open", (x, w) -> {
                if (pin.equals(in.getText().toString())) openSettings();
            })
            .setNegativeButton("Cancel", null).create();
        d.setOnDismissListener(x -> immersive());
        d.show();
        in.requestFocus();
    }

    private void openSettings() {
        startActivity(new Intent(this, SettingsActivity.class));
    }

    // ------------------------------------------------------------------ screenshots for the agent

    /**
     * JPEG of the player for the agent: the whole page, the player area (player=1) or area=x,y,w,h in CSS pixels,
     * scaled to max=<longest edge>. Runs on a control thread.
     */
    static byte[] capture(String query) throws Exception {
        final PlayerActivity a = current;
        if (a == null || a.web == null) {
            throw new Exception("the built-in player is not on screen (screenshots need the built-in player)");
        }
        String area = null;
        boolean player = false;
        int max = 1280;
        for (String kv : query.split("&")) {
            int eq = kv.indexOf('=');
            if (eq < 0) continue;
            String k = kv.substring(0, eq), v = java.net.URLDecoder.decode(kv.substring(eq + 1), "UTF-8");
            if (k.equals("area")) area = v;
            else if (k.equals("player")) player = v.equals("1");
            else if (k.equals("max")) max = Integer.parseInt(v);
        }
        final float dp = a.getResources().getDisplayMetrics().density; // CSS pixel = dp in the WebView
        float[] r = null;
        if (area != null) {
            String[] p = area.split(",");
            r = new float[] {Float.parseFloat(p[0]), Float.parseFloat(p[1]), Float.parseFloat(p[2]), Float.parseFloat(p[3])};
        } else if (player) {
            r = playerRect(a);
        }
        final Bitmap[] shot = new Bitmap[1];
        final Exception[] err = new Exception[1];
        final CountDownLatch done = new CountDownLatch(1);
        final float[] rect = r;
        ui.post(() -> {
            try {
                WebView w = a.web;
                int[] at = new int[2];
                w.getLocationInWindow(at);
                Rect src = new Rect(at[0], at[1], at[0] + w.getWidth(), at[1] + w.getHeight());
                if (rect != null) {
                    src = new Rect(at[0] + Math.round(rect[0] * dp), at[1] + Math.round(rect[1] * dp),
                        at[0] + Math.round((rect[0] + rect[2]) * dp), at[1] + Math.round((rect[1] + rect[3]) * dp));
                    if (!src.intersect(at[0], at[1], at[0] + w.getWidth(), at[1] + w.getHeight())) {
                        throw new Exception("area is outside the screen");
                    }
                }
                final Bitmap bmp = Bitmap.createBitmap(src.width(), src.height(), Bitmap.Config.ARGB_8888);
                if (Build.VERSION.SDK_INT >= 26) {
                    // The screen as shown, videos included.
                    PixelCopy.request(a.getWindow(), src, bmp, res -> {
                        if (res == PixelCopy.SUCCESS) shot[0] = bmp;
                        else err[0] = new Exception("screen copy failed (" + res + ")");
                        done.countDown();
                    }, ui);
                    return;
                }
                Canvas c = new Canvas(bmp);
                c.translate(at[0] - src.left, at[1] - src.top);
                w.draw(c);
                shot[0] = bmp;
            } catch (Exception e) {
                err[0] = e;
            }
            done.countDown();
        });
        if (!done.await(15, TimeUnit.SECONDS)) throw new Exception("screenshot timed out");
        if (err[0] != null) throw err[0];
        Bitmap bmp = shot[0];
        float scale = Math.min(1f, max / (float) Math.max(bmp.getWidth(), bmp.getHeight()));
        if (scale < 1f) {
            bmp = Bitmap.createScaledBitmap(bmp, Math.max(1, Math.round(bmp.getWidth() * scale)),
                Math.max(1, Math.round(bmp.getHeight() * scale)), true);
        }
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        bmp.compress(Bitmap.CompressFormat.JPEG, 75, out);
        return out.toByteArray();
    }

    /** The player area (#pp-root) in CSS pixels, or null for the whole page. */
    private static float[] playerRect(final PlayerActivity a) throws Exception {
        final String[] json = new String[1];
        final CountDownLatch done = new CountDownLatch(1);
        ui.post(() -> {
            if (a.web == null) {
                done.countDown();
                return;
            }
            a.web.evaluateJavascript("(function () { var r = document.getElementById('pp-root'); if (!r) return null;"
                + " var b = r.getBoundingClientRect(); return [b.left, b.top, b.width, b.height]; })()", v -> {
                    json[0] = v;
                    done.countDown();
                });
        });
        done.await(5, TimeUnit.SECONDS);
        if (json[0] == null || !json[0].startsWith("[")) return null;
        JSONArray arr = new JSONArray(json[0]);
        if (arr.getDouble(2) < 1 || arr.getDouble(3) < 1) return null;
        return new float[] {(float) arr.getDouble(0), (float) arr.getDouble(1), (float) arr.getDouble(2), (float) arr.getDouble(3)};
    }
}
