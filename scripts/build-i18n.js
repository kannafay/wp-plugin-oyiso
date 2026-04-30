const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const languagesDir = path.join(repoRoot, 'languages');

function loadGettextParser() {
  try {
    return require('gettext-parser');
  } catch (error) {
    const detail = error && error.message ? error.message : String(error);
    throw new Error(
      `无法加载 gettext-parser 运行依赖。请先执行 pnpm install，确保 encoding 等依赖已安装。\n原始错误: ${detail}`
    );
  }
}

function getTranslationFiles() {
  return fs
    .readdirSync(languagesDir, { withFileTypes: true })
    .filter((entry) => entry.isFile() && /^oyiso-[^.]+\.po$/i.test(entry.name))
    .map((entry) => entry.name)
    .sort();
}

function buildMoFromPo(poFilename) {
  const gettextParser = loadGettextParser();
  const poPath = path.join(languagesDir, poFilename);
  const moPath = path.join(languagesDir, poFilename.replace(/\.po$/i, '.mo'));
  const poBuffer = fs.readFileSync(poPath);
  const poData = gettextParser.po.parse(poBuffer);
  const moBuffer = gettextParser.mo.compile(poData);

  fs.writeFileSync(moPath, moBuffer);

  return {
    poFilename,
    moFilename: path.basename(moPath),
    size: moBuffer.length,
  };
}

function main() {
  if (!fs.existsSync(languagesDir)) {
    throw new Error(`Languages directory not found: ${languagesDir}`);
  }

  const translationFiles = getTranslationFiles();

  if (translationFiles.length === 0) {
    console.log('No oyiso translation .po files found.');
    return;
  }

  console.log(`Building ${translationFiles.length} translation file(s)...`);

  for (const poFilename of translationFiles) {
    const result = buildMoFromPo(poFilename);
    console.log(`  ${result.poFilename} -> ${result.moFilename} (${result.size} bytes)`);
  }

  console.log('Translation build complete.');
}

main();
