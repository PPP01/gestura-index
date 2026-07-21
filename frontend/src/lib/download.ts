/** Baut einen sicheren Dateinamen aus formatId und Version. */
export function buildDownloadFilename(formatId: string, semver: string): string {
	const safe = `${formatId}-${semver}`.replace(/[^a-zA-Z0-9._-]/g, '-');
	return `${safe}.json`;
}

/** Bietet ein JSON-Objekt als Datei-Download an (nur im Browser). */
export function triggerJsonDownload(data: unknown, filename: string): void {
	const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = filename;
	document.body.appendChild(a);
	a.click();
	a.remove();
	URL.revokeObjectURL(url);
}
