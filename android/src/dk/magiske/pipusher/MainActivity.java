package dk.magiske.pipusher;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;

/** App icon (and Home, when set as home app): straight to the player once set up, the settings otherwise. */
public class MainActivity extends Activity {
    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        Prefs p = new Prefs(this);
        if (p.configured()) {
            AgentService.start(this, false, false);
            p.launchPlayer(this);
        } else {
            startActivity(new Intent(this, SettingsActivity.class));
        }
        finish();
    }
}
