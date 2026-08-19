const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');
const os = require('os');
const { execSync, spawn } = require('child_process');

const PHP_ZIP_URL = 'https://windows.php.net/downloads/releases/php-8.3.10-nts-Win32-vs16-x64.zip';
const PHP_ARCHIVES_ZIP_URL = 'https://windows.php.net/downloads/releases/archives/php-8.3.10-nts-Win32-vs16-x64.zip';
const WORKSPACE_DIR = __dirname;
const PHP_DIR = path.join(WORKSPACE_DIR, 'php-bin');
const ZIP_PATH = path.join(WORKSPACE_DIR, 'php.zip');
const PORT = 8443; // HTTPS port
const HTTP_PORT = 8000; // HTTP fallback

function log(msg) {
  console.log(`[LOG] ${new Date().toLocaleTimeString()}: ${msg}`);
}

// Auto-detect local WiFi / LAN IP address
function getLocalIP() {
  const interfaces = os.networkInterfaces();
  const candidates = [];
  for (const name of Object.keys(interfaces)) {
    for (const iface of interfaces[name]) {
      // Skip loopback and non-IPv4
      if (iface.family === 'IPv4' && !iface.internal) {
        candidates.push({ name, address: iface.address });
      }
    }
  }
  // Prefer WiFi / WLAN interfaces, then Ethernet, then any
  const wifi = candidates.find(c => /wi.?fi|wlan|wireless/i.test(c.name));
  const eth  = candidates.find(c => /eth|local area|lan/i.test(c.name));
  const chosen = wifi || eth || candidates[0];
  return chosen ? chosen.address : '127.0.0.1';
}

function printBanner(localIP, useHttps) {
  const scheme = useHttps ? 'https' : 'http';
  const port   = useHttps ? PORT : HTTP_PORT;
  const networkUrl = `${scheme}://${localIP}:${port}`;
  const localUrl   = `${scheme}://127.0.0.1:${port}`;

  console.log('');
  console.log('╔══════════════════════════════════════════════════════════╗');
  console.log(`║         🏭  Gas Agency CRM — ${useHttps ? 'HTTPS 🔒' : 'HTTP'} Server Running       ║`);
  console.log('╠══════════════════════════════════════════════════════════╣');
  console.log(`║  📍 This PC (Local):   ${localUrl.padEnd(34)}║`);
  console.log(`║  🌐 WiFi Network URL:  ${networkUrl.padEnd(34)}║`);
  console.log('║                                                          ║');
  console.log('║  Share the WiFi Network URL with other PCs/Mobiles       ║');
  console.log('║  on the same WiFi to access this portal from anywhere!   ║');
  if (useHttps) {
  console.log('║  ⚠️  First visit: click Advanced → Proceed (per device)  ║');
  }
  console.log('╚══════════════════════════════════════════════════════════╝');
  console.log('');
}

function downloadPHP(url = PHP_ZIP_URL) {
  return new Promise((resolve, reject) => {
    log(`Requesting URL: ${url}`);
    https.get(url, (response) => {
      // Handle redirect
      if ([301, 302, 307, 308].includes(response.statusCode)) {
        let location = response.headers.location;
        if (!location) {
          reject(new Error(`Redirect response missing Location header with code ${response.statusCode}`));
          return;
        }
        log(`Redirected (status ${response.statusCode}) to: ${location}`);
        // Handle relative redirect url
        if (!location.startsWith('http')) {
          location = new URL(location, url).href;
        }
        resolve(downloadPHP(location));
        return;
      }

      // Handle 404 fallback to archives
      if (response.statusCode === 404 && url !== PHP_ARCHIVES_ZIP_URL) {
        log(`PHP version not found in active releases (404). Trying archives folder...`);
        resolve(downloadPHP(PHP_ARCHIVES_ZIP_URL));
        return;
      }

      if (response.statusCode !== 200) {
        reject(new Error(`Failed to download PHP. HTTP Status: ${response.statusCode}`));
        return;
      }
      
      const file = fs.createWriteStream(ZIP_PATH);
      const totalBytes = parseInt(response.headers['content-length'], 10) || 0;
      let downloadedBytes = 0;
      let lastReport = 0;

      response.pipe(file);

      response.on('data', (chunk) => {
        downloadedBytes += chunk.length;
        if (totalBytes > 0) {
          const percent = Math.floor((downloadedBytes / totalBytes) * 100);
          if (percent - lastReport >= 10 || percent === 100) {
            log(`Download Progress: ${percent}% (${(downloadedBytes / (1024 * 1024)).toFixed(1)} MB / ${(totalBytes / (1024 * 1024)).toFixed(1)} MB)`);
            lastReport = percent;
          }
        }
      });

      file.on('finish', () => {
        file.close();
        log('Download completed successfully.');
        resolve();
      });
    }).on('error', (err) => {
      fs.unlink(ZIP_PATH, () => {});
      reject(err);
    });
  });
}

function extractPHP() {
  log('Extracting PHP zip archive using Windows PowerShell...');
  if (!fs.existsSync(PHP_DIR)) {
    fs.mkdirSync(PHP_DIR, { recursive: true });
  }
  
  // Use PowerShell to extract the zip file natively
  const command = `powershell -NoProfile -Command "Expand-Archive -Path '${ZIP_PATH}' -DestinationPath '${PHP_DIR}' -Force"`;
  try {
    execSync(command, { stdio: 'inherit' });
    log('Extraction complete.');
  } catch (error) {
    throw new Error(`Failed to extract PHP zip: ${error.message}`);
  }
}

function configurePHP() {
  log('Configuring php.ini file with SQLite modules...');
  const iniPath = path.join(PHP_DIR, 'php.ini');
  
  const iniContent = `
[PHP]
max_execution_time = 300
memory_limit = 128M
error_reporting = E_ALL
display_errors = On
display_startup_errors = On
post_max_size = 50M
upload_max_filesize = 50M
default_charset = "UTF-8"

extension_dir = "ext"

extension=curl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3

[Date]
date.timezone = "Asia/Kolkata"
`;

  fs.writeFileSync(iniPath, iniContent.trim(), 'utf8');
  log(`php.ini written at ${iniPath}`);
}

function cleanUp() {
  log('Cleaning up temporary zip file...');
  if (fs.existsSync(ZIP_PATH)) {
    fs.unlinkSync(ZIP_PATH);
    log('Temporary zip file removed.');
  }
}

function openBrowser(localIP, useHttps) {
  const scheme = useHttps ? 'https' : 'http';
  const port   = useHttps ? PORT : HTTP_PORT;
  const url = `${scheme}://${localIP}:${port}`;
  log(`Opening application in default web browser: ${url}`);
  const cmd = process.platform === 'win32' ? `start ${url}` : `open ${url}`;
  try {
    execSync(cmd);
  } catch (e) {
    log(`Could not automatically open browser. Please open ${url} manually.`);
  }
}

// Save the detected network IP into a file so PHP can read it
function saveNetworkIP(ip, useHttps) {
  const scheme = useHttps ? 'https' : 'http';
  const port   = useHttps ? PORT : HTTP_PORT;
  const configPath = path.join(WORKSPACE_DIR, 'network_config.json');
  const config = { ip, port, url: `${scheme}://${ip}:${port}`, https: useHttps, generated: new Date().toISOString() };
  fs.writeFileSync(configPath, JSON.stringify(config, null, 2), 'utf8');
}

async function main() {
  try {
    const localIP = getLocalIP();

    // Check for SSL certificate files
    const certPath = path.join(WORKSPACE_DIR, 'server.crt');
    const keyPath  = path.join(WORKSPACE_DIR, 'server.key');
    const useHttps = fs.existsSync(certPath) && fs.existsSync(keyPath);
    const publicPort  = useHttps ? PORT  : HTTP_PORT;   // port users connect to
    const phpPort     = useHttps ? 18000 : HTTP_PORT;   // internal PHP port

    saveNetworkIP(localIP, useHttps);

    // 1. Check if PHP is already downloaded and extracted
    const phpExePath = path.join(PHP_DIR, 'php.exe');
    if (!fs.existsSync(phpExePath)) {
      await downloadPHP();
      extractPHP();
      configurePHP();
      cleanUp();
    } else {
      log('Portable PHP environment detected. Skipping download and extraction.');
      configurePHP();
    }

    // 2. Start PHP on internal port
    log(`Starting PHP server on 0.0.0.0:${phpPort}...`);
    // By not specifying a file, PHP defaults to index.php
    const phpProcess = spawn(phpExePath, ['-S', `0.0.0.0:${phpPort}`], {
      cwd: WORKSPACE_DIR,
      stdio: 'inherit'
    });

    phpProcess.on('error', (err) => console.error('PHP Server error:', err));
    phpProcess.on('exit', (code) => log(`PHP Server stopped (Exit code: ${code})`));

    // 3. If HTTPS certs available, create Node.js HTTPS reverse proxy
    if (useHttps) {
      await new Promise(r => setTimeout(r, 800)); // wait for PHP to bind

      const tlsOptions = {
        cert: fs.readFileSync(certPath),
        key:  fs.readFileSync(keyPath),
      };

      const httpsServer = require('https').createServer(tlsOptions, (req, res) => {
        const options = {
          hostname: '127.0.0.1',
          port: phpPort,
          path: req.url,
          method: req.method,
          headers: { ...req.headers, host: `127.0.0.1:${phpPort}` },
        };
        const proxy = require('http').request(options, (phpRes) => {
          res.writeHead(phpRes.statusCode, phpRes.headers);
          phpRes.pipe(res);
        });
        proxy.on('error', (e) => {
          res.writeHead(502);
          res.end('PHP server not ready: ' + e.message);
        });
        req.pipe(proxy);
      });

      httpsServer.listen(publicPort, '0.0.0.0', () => {
        log(`HTTPS reverse proxy listening on 0.0.0.0:${publicPort}`);
      });

      httpsServer.on('error', (err) => {
        console.error('HTTPS proxy error:', err);
      });
    }

    // 4. Print network access banner
    setTimeout(() => {
      printBanner(localIP, useHttps);
      openBrowser(localIP, useHttps);
    }, 2000);

  } catch (error) {
    console.error('Error during setup or startup:', error);
  }
}

main();
