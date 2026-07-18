import assert from "node:assert/strict";
import crypto from "node:crypto";
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
assert.ok(seededIds.size >= 17, "Seed data must include the complete ecosystem graph.");

const architectureFiles = [
  "README.md",
  "BUILD_WEEK_SUBMISSION.md",
  "DEMO_SCRIPT.md",
  "ipmdb/ecosystem.php",
  "ipmdb/database/seed.sql",
];

const architectureTerms = [
  "DAD",
  "DADS",
  "Sandola",
  "COPO",
  "The Mill",
  "Housing",
  "Governance",
  "Transportation",
  "PCWM",
  "Public Service",
  "Economic Security",
  "Make",
  "Measure",
  "Map",
  "Model",
  "Memorize",
  "Merge",
  "Mature",
];

for (const file of architectureFiles) {
  const source = read(file);
  for (const term of architectureTerms) {
    assert.ok(source.includes(term), `${file} omits required ecosystem component: ${term}`);
  }
}

const publicDad = read("ipmdb/dad/index.php");
assert.match(publicDad, /Priority 1/, "DAD must remain the Priority 1 implementation.");
assert.doesNotMatch(publicDad, /ajfisherco/i, "The public DAD page exposes AJF branding or contact information.");

for (const file of ["ipmdb/ecosystem.php", "ipmdb/dad/index.php", "nodes/sandola/index.html"]) {
  assert.doesNotMatch(read(file), /AJF|Alexander John Fisher|ajfisherco/i, `${file} exposes workshop branding on a public system surface.`);
}

const officialLogoHashes = new Map([
  ["ipmdb/assets/brand/ipmdb-i2a-official.jpeg", "0fe1fbaf39cce9d84b56c3892852af8807c4d01870c6061acdf0035d56488359"],
  ["ipmdb/assets/brand/dad-official.jpeg", "be0bcdbc2f57b94fce66355d4c07bdb5190527a10be03cc563c04edd3ee6ea31"],
  ["ipmdb/assets/brand/sandola-official.png", "c22a60a4d87994a6679ebfc8880456a8f5a9dc41d64f06bedae4d4876a8333b3"],
]);

for (const [file, expectedHash] of officialLogoHashes) {
  const bytes = fs.readFileSync(path.resolve(file));
  const actualHash = crypto.createHash("sha256").update(bytes).digest("hex");
  assert.equal(actualHash, expectedHash, `${file} is not the locked official logo file.`);
}

const thumbnailPath = path.resolve("graphics/ipmdb-build-week-thumbnail-v2.png");
const thumbnail = fs.readFileSync(thumbnailPath);
assert.equal(thumbnail.subarray(1, 4).toString("ascii"), "PNG", "Submission thumbnail must be a PNG.");
assert.equal(thumbnail.readUInt32BE(16), 1536, "Submission thumbnail must be 1536px wide.");
assert.equal(thumbnail.readUInt32BE(20), 1024, "Submission thumbnail must be 1024px high.");
assert.ok(thumbnail.length <= 5 * 1024 * 1024, "Submission thumbnail exceeds Devpost's 5MB limit.");

for (const file of ["README.md", "BUILD_WEEK_SUBMISSION.md", "DEMO_SCRIPT.md", "LICENSE", "docker-compose.yml", "ipmdb/ecosystem.php", "graphics/ipmdb-build-week-thumbnail-v2.png"]) {
  assert.ok(fs.existsSync(path.resolve(file)), `Required submission file is missing: ${file}`);
}

console.log("Submission integrity and security assertions passed.");
