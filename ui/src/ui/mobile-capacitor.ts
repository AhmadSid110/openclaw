import { App } from "@capacitor/app";
import { Capacitor } from "@capacitor/core";
import { LocalNotifications } from "@capacitor/local-notifications";
import { PushNotifications } from "@capacitor/push-notifications";

const isNativeCapacitor = Capacitor.isNativePlatform?.() ?? false;

if (!isNativeCapacitor) {
  console.debug("[mobile] Capacitor bridge skipped (not running natively)");
} else {
  console.debug("[mobile] Capacitor bridge enabled");

  let appActive = true;
  let permissionGranted = false;
  let notificationCounter = 1;

  const updatePermission = async () => {
    try {
      const result = await LocalNotifications.requestPermissions();
      permissionGranted = result.display === "granted";

      if (permissionGranted) {
        await LocalNotifications.createChannel({
          id: "openclaw_assistant",
          name: "Assistant Messages",
          description: "Notifications for incoming assistant replies",
          importance: 5,
          visibility: 1,
          vibration: true,
        });
      }
    } catch (err) {
      console.warn("[mobile] local notification permission/channel failed", err);
    }
  };

  void updatePermission();

  App.addListener("appStateChange", (state) => {
    appActive = state.isActive;
    if (appActive && !permissionGranted) {
      void updatePermission();
    }
  });

  window.addEventListener("openclaw:assistant-message", (event: Event) => {
    const custom = event as CustomEvent<{ text?: string }>;
    const rawText = custom.detail?.text?.trim();
    if (!rawText || appActive || !permissionGranted) {
      return;
    }
    const body = rawText.length > 120 ? `${rawText.slice(0, 117)}…` : rawText;
    const id = notificationCounter++;
    void LocalNotifications.schedule({
      notifications: [
        {
          id,
          title: "Sibyl",
          body,
          channelId: "openclaw_assistant",
          extra: { source: "openclaw", type: "assistant" },
        },
      ],
    }).catch((err) => {
      console.warn("[mobile] local notification failed", err);
    });
  });

  if (PushNotifications) {
    try {
      PushNotifications.addListener("registration", (token) => {
        console.debug("[mobile] push registration token", token.value);
      });
      PushNotifications.addListener("registrationError", (error) => {
        console.warn("[mobile] push registration error", error);
      });
      PushNotifications.addListener("pushNotificationReceived", (notification) => {
        console.debug("[mobile] inbound push", notification);
      });
      PushNotifications.addListener("pushNotificationActionPerformed", (action) => {
        console.debug("[mobile] push action", action);
      });

      // Wrap the registration call in a try-catch to prevent startup crashes on second launch
      const initPush = async () => {
        try {
          // Add a slight delay to ensure the native bridge is fully stabilized
          await new Promise((r) => setTimeout(r, 1000));
          const result = await PushNotifications.requestPermissions();
          if (result.receive === "granted") {
            await PushNotifications.register();
          } else {
            console.debug("[mobile] push permissions denied");
          }
        } catch (err) {
          console.warn("[mobile] push registration failed", err);
        }
      };
      void initPush();
    } catch (err) {
      console.warn("[mobile] PushNotifications initialization failed", err);
    }
  }
}
