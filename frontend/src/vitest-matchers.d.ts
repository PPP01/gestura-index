// Bindet die jest-dom-Matcher (toBeInTheDocument, toBeDisabled, ...) an Vitests
// `expect` – ohne diese Datei sieht `svelte-check` die Modul-Augmentierung aus
// `vitest-setup.ts` nicht, weil diese Datei außerhalb von `src/` liegt und nicht
// im generierten tsconfig-`include` steht.
import '@testing-library/jest-dom/vitest';
