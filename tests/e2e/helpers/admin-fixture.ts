import { execFileSync } from 'child_process';

export interface TempEditor {
  id: number;
  email: string;
  password: string;
}

const PASSWORD = 'PlaywrightEditor123!';

function mysql(sql: string): string {
  return execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-N', '-e', sql,
  ]).toString().trim();
}

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
function createTempUser(role: 'admin' | 'editor', emailPrefix: string): TempEditor {
  // Date.now() alone collides once enough specs create a user in the same
  // millisecond under fullyParallel workers — add a random suffix too.
  const email = `${emailPrefix}-${Date.now()}-${Math.random().toString(36).slice(2)}@example.com`;
  const hash = execFileSync('php', ['-r', `echo password_hash('${PASSWORD}', PASSWORD_BCRYPT);`])
    .toString()
    .trim();

  mysql(`INSERT INTO users (email, password_hash, role) VALUES ('${email}', '${hash}', '${role}')`);
  const id = parseInt(mysql(`SELECT id FROM users WHERE email = '${email}'`), 10);

  return { id, email, password: PASSWORD };
}

export function createTempEditor(): TempEditor {
  return createTempUser('editor', 'e2e-order-flow-editor');
}

export function createTempAdmin(): TempEditor {
  return createTempUser('admin', 'e2e-admin');
}

function deleteTempUser(email: string): void {
  mysql(`DELETE FROM users WHERE email = '${email}'`);
}

export function deleteTempEditor(email: string): void {
  deleteTempUser(email);
}

export function deleteTempAdmin(email: string): void {
  deleteTempUser(email);
}
