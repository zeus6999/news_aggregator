// fetch_tv_one.js
// Uso: node fetch_tv_one.js "<url>" "<outfile>" "<logfile>"
//
// ENV:
//   HEADLESS=0                  -> headed (con xvfb su server)
//   EXECUTABLE_PATH=/path/brave  -> usa Brave se installato
//   USER_DATA_DIR=/tmp/tv_profile-> profilo persistente
//   WAIT_MS=1200                -> attesa dopo goto
//   NETIDLE=1                   -> prova networkidle

const fs = require("fs");
const path = require("path");
const { chromium } = require("playwright");

function logLine(logFile, msg) {
  const line = `[${new Date().toISOString()}] ${msg}\n`;
  try { fs.appendFileSync(logFile, line); } catch (_) {}
}

(async () => {
  const url = process.argv[2];
  const outFile = process.argv[3];
  const logFile = process.argv[4];

  if (!url || !outFile || !logFile) {
    console.error("Uso: node fetch_tv_one.js <url> <outfile> <logfile>");
    process.exit(2);
  }

  fs.mkdirSync(path.dirname(outFile), { recursive: true });
  fs.mkdirSync(path.dirname(logFile), { recursive: true });

  const tmpFile = outFile + ".tmp";

  const headless = process.env.HEADLESS === "0" ? false : true;
  const execPath = process.env.EXECUTABLE_PATH || undefined;
  const userDataDir = process.env.USER_DATA_DIR || undefined;
  const waitMs = Number(process.env.WAIT_MS || "1200");
  const useNetIdle = process.env.NETIDLE === "1";

  logLine(logFile, `START url=${url} headless=${headless} execPath=${execPath || "playwright-chromium"} profile=${userDataDir || "TEMP"}`);

  const launchArgs = [
    "--no-sandbox",
    "--disable-dev-shm-usage",
    "--disable-blink-features=AutomationControlled",
  ];

  let context;
  let browser;

  try {
    if (userDataDir) {
      context = await chromium.launchPersistentContext(userDataDir, {
        headless,
        executablePath: execPath,
        args: launchArgs,
        viewport: { width: 1366, height: 768 },
        locale: "it-IT",
        timezoneId: "Europe/Rome",
      });
    } else {
      browser = await chromium.launch({
        headless,
        executablePath: execPath,
        args: launchArgs,
      });

      context = await browser.newContext({
        userAgent:
          "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        locale: "it-IT",
        timezoneId: "Europe/Rome",
        viewport: { width: 1366, height: 768 },
      });
    }

    const page = await context.newPage();

    // blocca immagini/font/media per velocizzare
    await page.route("**/*", (route) => {
      const rt = route.request().resourceType();
      if (rt === "image" || rt === "font" || rt === "media") return route.abort();
      return route.continue();
    });

    let lastErr = null;

    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        logLine(logFile, `GOTO attempt=${attempt}`);
        await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
        await page.waitForSelector("body", { timeout: 15000 });

        if (waitMs > 0) await page.waitForTimeout(waitMs);

        if (useNetIdle) {
          try { await page.waitForLoadState("networkidle", { timeout: 5000 }); } catch (_) {}
        }

        const html = await page.content();
        fs.writeFileSync(tmpFile, html, "utf8");
        fs.renameSync(tmpFile, outFile);

        logLine(logFile, `OK saved=${outFile} bytes=${Buffer.byteLength(html, "utf8")}`);

        await context.close();
        if (browser) await browser.close();
        process.exit(0);
      } catch (e) {
        lastErr = e;
        logLine(logFile, `ATTEMPT_FAIL ${e && e.message ? e.message : String(e)}`);
        try { await page.screenshot({ path: outFile + ".png", fullPage: true }); } catch (_) {}
      }
    }

    throw lastErr || new Error("Unknown failure");
  } catch (e) {
    logLine(logFile, `FAIL ${e && e.message ? e.message : String(e)}`);
    try { if (fs.existsSync(tmpFile)) fs.unlinkSync(tmpFile); } catch (_) {}
    try { if (context) await context.close(); } catch (_) {}
    try { if (browser) await browser.close(); } catch (_) {}
    process.exit(2);
  }
})();
