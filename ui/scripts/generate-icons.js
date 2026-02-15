import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, "..");
const svgSource = path.join(projectRoot, "src", "assets", "launcher-icon.svg");
const publicDir = path.join(projectRoot, "public");

const publicTargets = [
  { file: "favicon-32.png", size: 32 },
  { file: "apple-touch-icon.png", size: 180 },
  { file: "android-chrome-192x192.png", size: 192 },
  { file: "android-chrome-512x512.png", size: 512 },
];

const densityMap = [
  { suffix: "mdpi", size: 48 },
  { suffix: "hdpi", size: 72 },
  { suffix: "xhdpi", size: 96 },
  { suffix: "xxhdpi", size: 144 },
  { suffix: "xxxhdpi", size: 192 },
];

async function generatePng(destination, size) {
  await sharp(svgSource)
    .resize(size, size, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ quality: 90 })
    .toFile(destination);
}

async function ensurePublicIcons() {
  await fs.mkdir(publicDir, { recursive: true });
  await fs.copyFile(svgSource, path.join(publicDir, "favicon.svg"));
  for (const target of publicTargets) {
    const dest = path.join(publicDir, target.file);
    console.info("[icons] generating", dest);
    await generatePng(dest, target.size);
  }
}

async function ensureAndroidIcons() {
  const resRoot = path.join(projectRoot, "android", "app", "src", "main", "res");
  try {
    await fs.access(resRoot);
  } catch (err) {
    console.warn("[icons] android res folder missing, skip generating android icons");
    return;
  }

  for (const { suffix, size } of densityMap) {
    const dir = path.join(resRoot, `mipmap-${suffix}`);
    await fs.mkdir(dir, { recursive: true });
    for (const name of ["ic_launcher.png", "ic_launcher_round.png"]) {
      const dest = path.join(dir, name);
      console.info("[icons] generating android", dest);
      await generatePng(dest, size);
    }
  }
}

async function main() {
  try {
    await ensurePublicIcons();
    await ensureAndroidIcons();
    console.info("[icons] generation complete");
  } catch (err) {
    console.error("[icons] failed to generate icons", err);
    process.exitCode = 1;
  }
}

main();
