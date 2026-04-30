const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const { spawnSync } = require('child_process');

const repoRoot = path.resolve(__dirname, '..');
const pluginDir = 'wp-plugin-oyiso';
const pluginMainFile = path.join(repoRoot, 'oyiso.php');
const pluginMainContent = fs.readFileSync(pluginMainFile, 'utf8');
const versionMatch = pluginMainContent.match(/^[ \t/*#@]*Version:\s*(.+)$/mi);

if (!versionMatch) {
  throw new Error('无法从 oyiso.php 读取插件版本号');
}

const pluginVersion = versionMatch[1].trim();
const zipName = `${pluginDir}_v${pluginVersion}.zip`;
const distDir = path.join(repoRoot, 'dist');
const i18nBuildScript = path.join(__dirname, 'build-i18n.js');

const includes = [
  'assets',
  'classes',
  'fields',
  'functions',
  'languages',
  'src',
  'vendor',
  'views',
  'index.php',
  'oyiso.php',
  'LICENSE.md',
  'README.md',
];

const excludes = [
  'vendor/bin/**',
  'vendor/php-stubs/**',
  'samples/**',
];

function runI18nBuild() {
  if (!fs.existsSync(i18nBuildScript)) {
    console.log('  ⚠️  未找到 scripts/build-i18n.js，跳过 .mo 构建');
    return;
  }

  console.log('\n🌐 先构建语言文件 (.mo) ...\n');

  const result = spawnSync(process.execPath, [i18nBuildScript], {
    cwd: repoRoot,
    stdio: 'inherit',
  });

  if (result.status !== 0) {
    throw new Error('语言文件构建失败，已中止打包');
  }
}

runI18nBuild();

if (fs.existsSync(distDir)) {
  fs.rmSync(distDir, { recursive: true });
}
fs.mkdirSync(distDir);

console.log(`\n📦 正在打包 ${zipName} ...\n`);

const zipPath = path.join(distDir, zipName);
const output = fs.createWriteStream(zipPath);
const archive = archiver('zip', { zlib: { level: 9 } });

output.on('close', () => {
  const size = (archive.pointer() / 1024).toFixed(1);
  console.log(`\n✅ 打包完成: dist/${zipName} (${size} KB)\n`);
});

archive.on('error', (err) => {
  console.error('❌ 打包失败:', err.message);
  process.exit(1);
});

archive.pipe(output);

for (const item of includes) {
  const src = path.join(repoRoot, item);

  if (!fs.existsSync(src)) {
    console.log(`  ⚠️  跳过不存在的: ${item}`);
    continue;
  }

  if (fs.statSync(src).isDirectory()) {
    archive.directory(src, `${pluginDir}/${item}`, (entry) => {
      const rel = `${item}/${entry.name}`;
      for (const pattern of excludes) {
        const prefix = pattern.replace('/**', '');
        if (rel.startsWith(prefix)) return false;
      }
      return entry;
    });
    console.log(`  📁 ${item}/`);
  } else {
    archive.file(src, { name: `${pluginDir}/${item}` });
    console.log(`  📄 ${item}`);
  }
}

archive.finalize();
