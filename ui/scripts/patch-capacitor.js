import fs from "node:fs/promises";
import path from "node:path";

async function main() {
  const filePath = path.join(
    "node_modules",
    "@capacitor",
    "android",
    "capacitor",
    "src",
    "main",
    "java",
    "com",
    "getcapacitor",
    "plugin",
    "SystemBars.java",
  );
  try {
    let content = await fs.readFile(filePath, { encoding: "utf8" });
    const replaced = content.replace(/Build\.VERSION_CODES\.VANILLA_ICE_CREAM/g, "35");
    if (replaced === content) {
      console.info("[plugins] SystemBars already pinned to 35");
      return;
    }
    await fs.writeFile(filePath, replaced, { encoding: "utf8" });
    console.info("[plugins] patched SystemBars to Android 35");
  } catch (err) {
    console.warn("[plugins] could not patch SystemBars", err);
  }
}

main().catch((err) => {
  console.error("[plugins] patch failed", err);
  process.exitCode = 1;
});
