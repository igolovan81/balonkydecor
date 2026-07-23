import { execFileSync } from 'child_process';

export interface TempEditor {
  email: string;
  password: string;
}

const PASSWORD = 'PlaywrightEditor123!';

// Test-side only: no seeded admin/editor credentials exist anywhere in this
// project, and /admin/setup only works when the users table is empty (it
// isn't, in local dev). This shells out to the same Docker MySQL every other
// local workflow uses, mirroring the uniqid()-fixture convention PHPUnit
// tests already follow (.claude/rules/unit-testing.md).
//
// Uses execFileSync (argv array, no shell) rather than execSync — a bcrypt
// hash contains literal `$` characters (e.g. $2y$12$...), which a shell
// would otherwise try to expand as variables when the hash lands inside a
// double-quoted -e argument.
export function createTempEditor(): TempEditor {
  const email = `e2e-order-flow-editor-${Date.now()}@example.com`;
  const hash = execFileSync('php', ['-r', `echo password_hash('${PASSWORD}', PASSWORD_BCRYPT);`])
    .toString()
    .trim();

  const sql = `INSERT INTO users (email, password_hash, role) VALUES ('${email}', '${hash}', 'editor')`;
  execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-e', sql,
  ]);

  return { email, password: PASSWORD };
}

export function deleteTempEditor(email: string): void {
  const sql = `DELETE FROM users WHERE email = '${email}'`;
  execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-e', sql,
  ]);
}
