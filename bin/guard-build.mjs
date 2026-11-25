#!/usr/bin/env node
/**
 * Adds WordPress's direct access guard to the PHP files that `wp-scripts build` writes (`*.asset.php`,
 * `blocks-manifest.php`): they only return an array, but every PHP file of a plugin should stop when opened directly.
 * Files that already have the guard (copied `render.php` files) stay as they are.
 *
 * Usage: node bin/guard-build.mjs [folder]   (default: build; run after every build, see package.json)
 */
/* eslint-disable no-console -- A command-line script: its output is the console. */
import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const GUARD = "defined( 'ABSPATH' ) || exit;";
const GUARDED = "defined( 'ABSPATH' )";
const root = process.argv[ 2 ] ?? 'build';
let changed = 0;

for ( const entry of await readdir( root, {
	recursive: true,
	withFileTypes: true,
} ) ) {
	if ( ! entry.isFile() || ! entry.name.endsWith( '.php' ) ) {
		continue;
	}
	const file = join( entry.parentPath, entry.name );
	const contents = await readFile( file, 'utf8' );
	if ( contents.includes( GUARDED ) ) {
		continue;
	}
	if ( ! contents.startsWith( '<?php' ) ) {
		console.error( `${ file } does not start with <?php.` );
		process.exitCode = 1;
		continue;
	}
	await writeFile( file, `<?php ${ GUARD }${ contents.slice( 5 ) }` );
	changed++;
}
console.log(
	`Direct access guard added to ${ changed } file(s) in ${ root }.`
);
