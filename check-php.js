const fs = require('fs');

// Read the PHP file
const content = fs.readFileSync('/tmp/cc-agent/57881389/project/seo-content-generator.php', 'utf8');

// Check for common PHP syntax issues
const issues = [];

// Check for unclosed strings
const lines = content.split('\n');
lines.forEach((line, index) => {
    const lineNum = index + 1;

    // Skip comments
    if (line.trim().startsWith('//') || line.trim().startsWith('*') || line.trim().startsWith('/*')) {
        return;
    }

    // Count quotes (basic check)
    const singleQuotes = (line.match(/(?<!\\)'/g) || []).length;
    const doubleQuotes = (line.match(/(?<!\\)"/g) || []).length;

    if (singleQuotes % 2 !== 0 && !line.includes('?>') && !line.includes('<?php')) {
        issues.push(`Line ${lineNum}: Possible unclosed single quote`);
    }
    if (doubleQuotes % 2 !== 0 && !line.includes('?>') && !line.includes('<?php')) {
        issues.push(`Line ${lineNum}: Possible unclosed double quote`);
    }
});

// Check for balanced braces
let braceCount = 0;
let bracketCount = 0;
let parenCount = 0;

for (let i = 0; i < content.length; i++) {
    const char = content[i];
    if (char === '{') braceCount++;
    if (char === '}') braceCount--;
    if (char === '[') bracketCount++;
    if (char === ']') bracketCount--;
    if (char === '(') parenCount++;
    if (char === ')') parenCount--;
}

if (braceCount !== 0) {
    issues.push(`Unbalanced braces: ${braceCount > 0 ? 'missing ' + braceCount + ' closing brace(s)' : 'extra ' + Math.abs(braceCount) + ' closing brace(s)'}`);
}

if (bracketCount !== 0) {
    issues.push(`Unbalanced brackets: ${bracketCount > 0 ? 'missing ' + bracketCount + ' closing bracket(s)' : 'extra ' + Math.abs(bracketCount) + ' closing bracket(s)'}`);
}

if (parenCount !== 0) {
    issues.push(`Unbalanced parentheses: ${parenCount > 0 ? 'missing ' + parenCount + ' closing paren(s)' : 'extra ' + Math.abs(parenCount) + ' closing paren(s)'}`);
}

// Check for PHP tags
const hasOpeningTag = content.includes('<?php');
const hasProperEnding = content.trim().endsWith('?>') || content.trim().endsWith(';');

if (!hasOpeningTag) {
    issues.push('Missing opening <?php tag');
}

console.log('\n=== PHP Syntax Check Results ===\n');
if (issues.length === 0) {
    console.log('✓ No obvious syntax issues found');
} else {
    console.log('Found potential issues:\n');
    issues.forEach(issue => console.log('  - ' + issue));
}

console.log(`\nTotal lines: ${lines.length}`);
console.log(`File size: ${(content.length / 1024).toFixed(2)} KB`);
