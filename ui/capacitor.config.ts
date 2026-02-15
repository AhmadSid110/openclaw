import { CapacitorConfig } from "@capacitor/cli";

const config: CapacitorConfig = {
  appId: "com.openclaw.app",
  appName: "OpenClaw",
  webDir: "../dist/control-ui",
  server: {
    androidScheme: "https",
    allowNavigation: ["*"],
  },
  plugins: {
    SplashScreen: {
      launchShowDuration: 2000,
      backgroundColor: "#000000",
      showSpinner: false,
      androidScaleType: "CENTER_CROP",
    },
  },
};

export default config;
