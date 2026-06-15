// Backend re-export of the single source-of-truth config at the project root.
// Importing from one place guarantees the pipeline and the API agree.
import config from '../../config';

export * from '../../config';
export { config };
export default config;
