package com.pipusher.client;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Device start (and app update): start the agent, and the player when "Start on boot" is on. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context c, Intent i) {
        Prefs p = new Prefs(c);
        if (!p.configured()) return;
        boolean updated = Intent.ACTION_MY_PACKAGE_REPLACED.equals(i.getAction());
        if (p.autostart() || updated) AgentService.start(c, false, p.autostart());
    }
}
