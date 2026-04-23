const fs = require('fs');
const path = require('path');
const archiver = require('archiver');

const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, 'package.json'), 'utf8'));
const pluginDir = 'wp-plugin-oyiso';
const zipName = `${pluginDir}-v${pkg.version}.zip`;
const distDir = path.join(__dirname, 'dist');

// 需要打包的文件/目录
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

// 排除的 glob 模式
const excludes = [
  'vendor/bin/**',
  'vendor/php-stubs/**',
  'samples/**',
];

// 清理旧产物
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
  const src = path.join(__dirname, item);

  if (!fs.existsSync(src)) {
    console.log(`  ⚠️  跳过不存在的: ${item}`);
    continue;
  }

  if (fs.statSync(src).isDirectory()) {
    archive.directory(src, `${pluginDir}/${item}`, (entry) => {
      // 检查是否匹配排除规则
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
