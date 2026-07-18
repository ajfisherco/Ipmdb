import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const read = (file) => fs.readFileSync(path.resolve(file), "utf8");

const graph = read("ipmdb/assets/js/relationship_graph.js");
assert.match(graph, /document\.addEventListener|querySelector/, "Graph bundle is not JavaScript.");
assert.doesNotMatch(graph.slice(0, 500), /:root\s*\{/, "Graph JavaScript was overwritten by CSS.");

const config = read("ipmdb/config.php");
assert.match(config, /getenv\('IPMDB_DB_DSN'\)/, "Database DSN must come from the environment.");
assert.match(config, /getenv\('OPENAI_API_KEY'\)/, "OpenAI API key must come from the environment.");
assert.doesNotMatch(config, /['"]pass['"]\s*=>\s*['"][^'"]+['"]/, "A database password is hard-coded in config.php.");

const auth = read("ipmdb/auth.php");
assert.match(auth, /password_verify\(/, "Admin authentication must verify a password hash.");
assert.doesNotMatch(auth, /password\s*===?\s*['"]/, "Admin authentication contains a plaintext password comparison.");

const adminMutations = [
  "admin_edit.php",
  "edit.php",
  "save_version.php",
  "relationship_add.php",
  "relationship_bulk.php",
  "relationship_delete.php",
  "relationship_edit.php",
  "relationship_import.php",
  "relationship_merge.php",
  "ai_relationships.php",
];

for (const file of adminMutations) {
  const source = read(`ipmdb/${file}`);
  assert.match(source, /ipmdb_require_login\(\)/, `${file} must require an admin session.`);
  assert.match(source, /ipmdb_require_csrf\(\)|ipmdb_csrf_field\(\)/, `${file} must use CSRF protection.`);
}

for (const file of ["viewer.php", "search.php", "history.php", "relationships.php"]) {
  const source = read(`ipmdb/${file}`);
  assert.doesNotMatch(source, /Origin:\s*<\?=|<strong>Email<\/strong>|AS\s+email\b/i, `${file} exposes originator email.`);
}

const schema = read("ipmdb/database/schema.sql");
for (const table of ["ipmdb_assets", "ipmdb_asset_versions", "ipmdb_relationships"]) {
  assert.match(schema, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`), `Schema is missing ${table}.`);
}

const seed = read("ipmdb/database/seed.sql");
const seededIds = new Set(seed.match(/IPMDB-\d{4}/g) ?? []);
assert.ok(seededIds.size >= 6, "Seed data must include at least six distinct assets.");

for (const file of ["README.md", "BUILD_WEEK_SUBMISSION.md", "DEMO_SCRIPT.md", "LICENSE", "docker-compose.yml"]) {
  assert.ok(fs.existsSync(path.resolve(file)), `Required submission file is missing: ${file}`);
}

console.log("Submission integrity and security assertions passed.");
