import { addAction } from '@wordpress/hooks';
import { registerModule } from '@divi/module-library';
import { createBRAPLModule } from './module-factory';

import labelMetadata from './modules/label/module.json';

const modules = [
  [labelMetadata, 'Labels not displayed in Builder'],
];

addAction('divi.moduleLibrary.registerModuleLibraryStore.after', 'brapl.divi5Modules', () => {
  modules.forEach(([metadata, placeholderLabel]) => {
    const module = createBRAPLModule(metadata, placeholderLabel);
    const { metadata: moduleMetadata, ...moduleConfig } = module;
    registerModule(moduleMetadata, moduleConfig);
  });
});
