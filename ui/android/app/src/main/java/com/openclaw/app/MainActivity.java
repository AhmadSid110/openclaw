package com.openclaw.app;

import android.graphics.Color;
import android.os.Bundle;
import androidx.core.view.WindowCompat;
import androidx.core.splashscreen.SplashScreen;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // official Android SplashScreen API must be initialized BEFORE super.onCreate
        try {
            SplashScreen.installSplashScreen(this);
        } catch (Exception e) {
            // Fallback for older devices or theme issues
        }
        
        // Pass null to prevent state restoration crashes on second launch
        super.onCreate(null);

        // Suggestion 3: Edge-to-Edge UI
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        
        // Make the status bar and navigation bar transparent.
        getWindow().setStatusBarColor(Color.TRANSPARENT);
        getWindow().setNavigationBarColor(Color.TRANSPARENT);
    }
}
