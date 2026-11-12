package dk.magiske.pipusher;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * Device start: start the agent, and the player when "Start on boot" is on. App update: start the agent again, and
 * the player only when it was on screen, so an update does not interrupt another app.
 */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context c, Intent i) {
        Prefs p = new Prefs(c);
        if (!p.configured()) return;
        if (Intent.ACTION_MY_PACKAGE_REPLACED.equals(i.getAction())) {
            AgentService.start(c, false, p.builtin() && p.showing());
        } else if (p.autostart()) {
            AgentService.start(c, false, true);
        }
    }
}
