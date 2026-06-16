const { execFile } = require("child_process");

const gcloud =
  "C:\\Users\\Welcome\\AppData\\Local\\Google\\Cloud SDK\\google-cloud-sdk\\bin\\gcloud.cmd";

const pollMs = 5000;
const clearAfterRequests = 50;
const once = process.argv.includes("--once");
let inFlight = false;
let printedRequests = 0;
const seenLines = new Set();

const colors = {
  red: "\x1b[31m",
  green: "\x1b[32m",
  yellow: "\x1b[33m",
  gray: "\x1b[90m",
  reset: "\x1b[0m",
};

function paint(color, value) {
  return `${colors[color]}${value}${colors.reset}`;
}

function statusColor(line) {
  const statusMatch = line.match(/\bstatus=(\d{3})\b|HTTP[/"\s.]*([1-5]\d{2})\b/i);
  const status = statusMatch ? Number(statusMatch[1] || statusMatch[2]) : null;

  if (
    (status && status >= 500) ||
    /\b(error|failed|failure|exception|critical|denied|timeout)\b/i.test(line)
  ) {
    return "red";
  }

  if (
    (status && status >= 200 && status < 300) ||
    /\b(success|successful|ok|sent|delivered|accepted|completed)\b/i.test(line)
  ) {
    return "green";
  }

  if (
    (status && status >= 400 && status < 500) ||
    /\b(warn|warning|retry|invalid|unauthorized|forbidden|not found)\b/i.test(line)
  ) {
    return "yellow";
  }

  return null;
}

function colorize(line) {
  const color = statusColor(line);
  const detailMatch = line.match(/\brequest="[^"]+"\s+status=\d{3}\s+bytes=\d+\s+duration_us=\d+\s+referer="[^"]*"/);

  if (!color || !detailMatch) {
    return color ? paint(color, line) : line;
  }

  return `${line.slice(0, detailMatch.index)}${paint(color, detailMatch[0])}${line.slice(
    detailMatch.index + detailMatch[0].length
  )}`;
}

function readLogs() {
  if (inFlight) {
    return;
  }

  inFlight = true;

  const command = [
    `& '${gcloud}'`,
    "run",
    "services",
    "logs",
    "read",
    "sms-api",
    "--region asia-southeast1",
    "--freshness=1m",
    "--limit=20",
    "--log-filter=NOLA_HTTP",
  ].join(" ");

  execFile(
    "powershell.exe",
    ["-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", command],
    { maxBuffer: 1024 * 1024 * 5, windowsHide: true },
    (error, stdout, stderr) => {
      inFlight = false;

      if (printedRequests >= clearAfterRequests) {
        console.clear();
        printedRequests = 0;
      }

      if (error) {
        console.error(paint("red", error.message));
      }

      if (stderr) {
        stderr
          .split(/\r?\n/)
          .filter(Boolean)
          .forEach((line) => console.error(paint("yellow", line)));
      }

      const newLines = stdout
        .split(/\r?\n/)
        .filter(Boolean)
        .filter((line) => {
          if (seenLines.has(line)) {
            return false;
          }

          seenLines.add(line);
          return true;
        });

      if (newLines.length === 0) {
        return;
      } else {
        newLines.forEach((line) => {
          console.log(colorize(line));
          printedRequests += 1;
        });
      }

      if (once) {
        process.exit(error ? 1 : 0);
      }
    }
  );
}

readLogs();
if (!once) {
  setInterval(readLogs, pollMs);
}
