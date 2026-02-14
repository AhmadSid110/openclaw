module.exports = {
  name: 'external-genai',
  init(pluginApi, config) {
    // noop: this plugin only provides config schema for models/providers
    return {
      name: 'external-genai'
    };
  }
};
