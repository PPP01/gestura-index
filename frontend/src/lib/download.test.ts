import { describe, it, expect, vi } from 'vitest';
import { buildDownloadFilename } from './download';

describe('buildDownloadFilename', () => {
	it('kombiniert formatId und semver zu einem .json-Namen', () => {
		expect(buildDownloadFilename('com.example.menu', '1.2.3')).toBe('com.example.menu-1.2.3.json');
	});
	it('ersetzt unzulässige Zeichen', () => {
		expect(buildDownloadFilename('a/b:c', '1.0.0')).toBe('a-b-c-1.0.0.json');
	});
});
