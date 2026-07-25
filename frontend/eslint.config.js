import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * Type-aware linting: the rules that catch real defects in this codebase (floating
 * promises, unsafe `any` flowing out of JSON.parse, misused awaits) all need type
 * information, so `recommendedTypeChecked` is used rather than the syntactic preset.
 */
export default tseslint.config(
  {
    ignores: ['dist', 'node_modules', 'coverage', 'test-results', 'playwright-report'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommendedTypeChecked,
  {
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },
  {
    files: ['src/**/*.ts', 'tests/**/*.ts'],
    languageOptions: {
      globals: globals.browser,
    },
  },
  {
    files: ['e2e/**/*.ts', '*.config.ts'],
    languageOptions: {
      globals: globals.node,
    },
  },
  {
    // This file configures the type-aware linter, so it cannot itself be part of the
    // TypeScript project the linter analyses.
    files: ['eslint.config.js'],
    ...tseslint.configs.disableTypeChecked,
    languageOptions: {
      // Keep disableTypeChecked's parser reset; only add the globals on top of it.
      ...tseslint.configs.disableTypeChecked.languageOptions,
      globals: globals.node,
    },
  },
  {
    rules: {
      // Unused arguments are legitimate when a callback signature is fixed; requiring a
      // leading underscore keeps that explicit.
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    },
  },
);
