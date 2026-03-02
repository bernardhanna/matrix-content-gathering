import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

async function main() {
  const inputPath = process.argv[2];
  const statusPath = process.argv[3] || '';
  if (!inputPath) {
    process.exit(0);
  }

  let tasks = [];
  try {
    const raw = await fs.readFile(inputPath, 'utf8');
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      tasks = parsed.filter((t) => t && t.url && t.selector && t.output);
    }
  } catch {
    process.exit(0);
  } finally {
    try { await fs.unlink(inputPath); } catch {}
  }

  if (tasks.length === 0) {
    if (statusPath) {
      try {
        await fs.writeFile(statusPath, JSON.stringify({ total: 0, completed: 0, generated: 0, done: true, error: '' }));
      } catch {}
    }
    process.exit(0);
  }

  const writeStatus = async (data) => {
    if (!statusPath) return;
    try {
      await fs.writeFile(statusPath, JSON.stringify(data));
    } catch {}
  };
  const forceVisible = async (locator) => {
    try {
      await locator.evaluate((el) => {
        const makeVisible = (node) => {
          if (!node || !node.style) return;
          node.style.setProperty('display', 'block', 'important');
          node.style.setProperty('visibility', 'visible', 'important');
          node.style.setProperty('opacity', '1', 'important');
          node.style.setProperty('max-height', 'none', 'important');
          node.style.setProperty('height', 'auto', 'important');
          node.style.setProperty('min-height', '120px', 'important');
        };
        makeVisible(el);
        let p = el.parentElement;
        let guard = 0;
        while (p && guard < 6) {
          makeVisible(p);
          p = p.parentElement;
          guard += 1;
        }
      });
    } catch {}
  };
  const resolveCaptureTarget = async (page, selector, baseLocator) => {
    let target = baseLocator;
    try {
      const meta = await baseLocator.evaluate((el) => ({
        tag: (el.tagName || '').toLowerCase(),
        cls: el.className || '',
        childCount: el.childElementCount || 0,
        hasSectionAncestor: !!(el.closest && el.closest('section')),
      }));
      if (meta && meta.tag !== 'section' && meta.hasSectionAncestor) {
        const sectionAncestor = baseLocator.locator('xpath=ancestor::section[1]').first();
        if ((await sectionAncestor.count()) > 0) {
          target = sectionAncestor;
        }
      }
      const looksLikeAnchor =
        meta &&
        meta.tag === 'div' &&
        meta.childCount === 0 &&
        String(meta.cls || '').indexOf('matrix-block-anchor') !== -1;
      if (looksLikeAnchor) {
        const sibling = page.locator(String(selector) + ' + *').first();
        if ((await sibling.count()) > 0) {
          target = sibling;
        }
      }
    } catch {}
    return target;
  };

  const findBlockLocator = async (page, task) => {
    const rawSelector = String(task.selector || '');
    const anchorId = rawSelector.startsWith('#') ? rawSelector.slice(1) : rawSelector;
    if (!anchorId) return { locator: null, matched: false };

    const esc = (s) => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const selectorsToTry = [
      '[data-matrix-block="' + esc(anchorId) + '"]',
      'section[data-matrix-block="' + esc(anchorId) + '"]',
      'section[id="' + esc(anchorId) + '"]',
      '[id="' + esc(anchorId) + '"]',
      '#' + anchorId.replace(/([^\w-])/g, '\\$1'),
    ];
    for (const sel of selectorsToTry) {
      const locator = page.locator(sel).first();
      if ((await locator.count()) > 0) return { locator, matched: true };
    }
    const sectionWithAnchor = page.locator('section:has([id="' + esc(anchorId) + '"])').first();
    if ((await sectionWithAnchor.count()) > 0) return { locator: sectionWithAnchor, matched: true };
    return { locator: null, matched: false };
  };

  let completed = 0;
  let generated = 0;
  const stats = {
    selector_matches: 0,
    section_fallback_matches: 0,
    no_target_matches: 0,
    capture_errors: 0,
    last_error: '',
  };
  await writeStatus({ total: tasks.length, completed, generated, done: false, error: '', stats });

  let browser;
  try {
    browser = await Promise.race([
      chromium.launch({
        headless: true,
        args: [
          '--no-sandbox',
          '--disable-setuid-sandbox',
          '--disable-dev-shm-usage',
          '--disable-gpu',
          '--disable-software-rasterizer',
          '--no-first-run',
        ],
      }),
      new Promise((_, rej) => setTimeout(() => rej(new Error('Browser launch timed out after 60s')), 60000)),
    ]);
  } catch (e) {
    const errMsg = e && (e.message || String(e)) ? String(e.message || e) : 'Browser launch failed';
    await writeStatus({ total: tasks.length, completed: 0, generated: 0, done: true, error: errMsg, stats });
    process.exit(1);
  }
  try {
    const byUrl = new Map();
    for (const task of tasks) {
      const key = String(task.url);
      if (!byUrl.has(key)) byUrl.set(key, []);
      byUrl.get(key).push(task);
    }

    for (const [url, urlTasks] of byUrl.entries()) {
      const context = await browser.newContext({
        viewport: { width: 1440, height: 2200 },
      });
      const firstTask = urlTasks[0] || {};
      if (Array.isArray(firstTask.cookies) && firstTask.cookies.length > 0) {
        try {
          await context.addCookies(firstTask.cookies);
        } catch {
          // Ignore cookie payload issues and continue with unauthenticated capture.
        }
      }
      const page = await context.newPage();

      try {
        let navigated = false;
        try {
          await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
          navigated = true;
        } catch {
          // Continue; we'll still attempt screenshot fallback.
        }
        if (!navigated) {
          try {
            await page.goto(url, { waitUntil: 'load', timeout: 20000 });
            navigated = true;
          } catch {
            // Continue even if navigation keeps failing.
          }
        }
        if (!navigated) {
          // Give the page a short chance to render any partial response.
          await page.waitForTimeout(1500);
        }

        for (const task of urlTasks) {
          try {
            await fs.mkdir(path.dirname(task.output), { recursive: true });
            const { locator: blockLocator, matched: byAnchor } = await findBlockLocator(page, task);
            if (byAnchor && blockLocator) {
              const target = await resolveCaptureTarget(page, String(task.selector), blockLocator);
              await forceVisible(target);
              await target.scrollIntoViewIfNeeded();
              await target.screenshot({
                path: String(task.output),
                type: 'jpeg',
                quality: 72,
                timeout: 15000,
              });
              stats.selector_matches += 1;
            } else {
              const idx = Number.isInteger(task.fallback_index) ? task.fallback_index : parseInt(task.fallback_index || '0', 10) || 0;
              const sections = page.locator('section');
              const sectionCount = await sections.count();
              if (sectionCount > idx) {
                const section = sections.nth(Math.max(0, idx));
                await forceVisible(section);
                await section.scrollIntoViewIfNeeded();
                await section.screenshot({
                  path: String(task.output),
                  type: 'jpeg',
                  quality: 70,
                  timeout: 15000,
                });
                stats.section_fallback_matches += 1;
              } else {
                stats.no_target_matches += 1;
              }
            }
          } catch (e) {
            // Keep going so one failed selector doesn't block all previews.
            stats.capture_errors += 1;
            stats.last_error = e && (e.message || String(e)) ? String(e.message || e) : 'capture error';
          } finally {
            completed += 1;
            try {
              await fs.access(String(task.output));
              generated += 1;
            } catch {}
            await writeStatus({ total: tasks.length, completed, generated, done: false, error: '', stats });
          }
        }
      } finally {
        await page.close();
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }

  await writeStatus({ total: tasks.length, completed, generated, done: true, error: '', stats });
}

main().catch((err) => {
  const statusPath = process.argv[3] || '';
  if (statusPath) {
    try {
      fs.writeFile(statusPath, JSON.stringify({
        total: 0,
        completed: 0,
        generated: 0,
        done: true,
        error: err && (err.message || String(err)) ? String(err.message || err) : 'unknown error',
        stats: {
          selector_matches: 0,
          section_fallback_matches: 0,
          no_target_matches: 0,
          capture_errors: 1,
          last_error: err && (err.message || String(err)) ? String(err.message || err) : 'unknown error',
        },
      }));
    } catch {}
  }
  try {
    const msg = err && (err.stack || err.message || String(err));
    console.error(msg);
  } catch {}
  process.exit(1);
});
