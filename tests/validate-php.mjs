import fs from "node:fs";
import path from "node:path";
import process from "node:process";
import PhpParser from "php-parser";

const root = path.resolve(process.cwd(), "ipmdb");
const parser = new PhpParser.Engine({
  parser: { extractDoc: false, php7: true },
  ast: { withPositions: true },
});

function filesUnder(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name);
    return entry.isDirectory() ? filesUnder(target) : [target];
  });
}

const phpFiles = filesUnder(root).filter((file) => file.endsWith(".php"));
const failures = [];

for (const file of phpFiles) {
  try {
    parser.parseCode(fs.readFileSync(file, "utf8"), file);
  } catch (error) {
    failures.push(`${path.relative(process.cwd(), file)}: ${error.message}`);
  }
}

if (failures.length > 0) {
  console.error(`PHP parse failures (${failures.length}):\n${failures.join("\n")}`);
  process.exit(1);
}

console.log(`Parsed ${phpFiles.length} PHP files successfully.`);
