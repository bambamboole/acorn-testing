/**
 * Unlighthouse crawls the site (via robots.txt, sitemap, link discovery) and
 * runs Lighthouse against each discovered page. CI mode (`unlighthouse-ci`)
 * exits non-zero when any audited page falls below the per-category budget.
 *
 * Published from `bambamboole/acorn-testing` via `wp acorn testing:setup`.
 * Edit freely — the package will not overwrite this file on subsequent setups.
 *
 * The accompanying Pest test (e.g. tests/Browser/LighthouseTest.php in the
 * starter) sets up FrankenPHP at 127.0.0.1:8080 and shells out to
 * `npx unlighthouse-ci --site http://127.0.0.1:8080`, which reads this file.
 */
export default {
  site: process.env.UNLIGHTHOUSE_SITE ?? "http://127.0.0.1:8080",

  ci: {
    // Per-category Lighthouse minimums (1–100). Any audited page that scores
    // below ANY of these in its respective category fails the assertion.
    budget: {
      performance: 80,
      accessibility: 95,
      "best-practices": 90,
      seo: 95,
    },
  },

  scanner: {
    // Cap so a runaway crawl on a seeded site doesn't audit hundreds of blog
    // posts or product pages. Raise it if you want fuller coverage.
    maxRoutes: 20,
  },
};
