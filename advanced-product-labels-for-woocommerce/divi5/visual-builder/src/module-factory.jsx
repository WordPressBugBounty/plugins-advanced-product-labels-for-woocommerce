import React, { useEffect, useMemo, useState } from 'react';

const {
  ModuleContainer,
  StyleContainer,
  elementClassnames,
} = window?.divi?.module || {};

const ModuleStyles = ({
  elements,
  settings,
  mode,
  state,
  noStyleTag,
}) => (
  <StyleContainer mode={mode} state={state} noStyleTag={noStyleTag}>
    {elements.style({
      attrName: 'module',
      styleProps: {
        disabledOn: {
          disabledModuleVisibility: settings?.disabledModuleVisibility,
        },
      },
    })}
  </StyleContainer>
);

const ModuleScriptData = ({ elements }) => (
  <React.Fragment>
    {elements.scriptData({
      attrName: 'module',
    })}
  </React.Fragment>
);

const moduleClassnames = ({ classnamesInstance, attrs }) => {
  classnamesInstance.add(
    elementClassnames({
      attrs: attrs?.module?.decoration ?? {},
    }),
  );
};

const option = (label) => ({ label });

const getPreviewConfig = () => {
  if (window?.BRAPLDivi5Preview) {
    return window.BRAPLDivi5Preview;
  }

  const scriptSrc = document?.currentScript?.src;
  if (!scriptSrc) {
    return {};
  }

  const params = new URL(scriptSrc).searchParams;

  return {
    ajaxUrl: decodeURIComponent(params.get('brapl_ajax_url') || ''),
    action: params.get('brapl_action') || '',
    nonce: params.get('brapl_nonce') || '',
  };
};

const previewConfig = getPreviewConfig();

const selectOptions = {
  type: {
    image: option('On Image'),
    label: option('Label'),
    all: option('All'),
  },
};

const fieldTypeMap = {
  type: { name: 'divi/select', props: { options: selectOptions.type } },
};

const normalizeMetadataFields = (metadata) => {
  metadata.settings = {
    ...(metadata.settings ?? {}),
    groups: {
      ...(metadata.settings?.groups ?? {}),
      contentMainContent: {
        panel: 'content',
        priority: 10,
        groupName: 'contentMainContent',
        multiElements: true,
        component: {
          name: 'divi/composite',
          props: {
            groupLabel: 'Content',
          },
        },
      },
    },
  };

  Object.entries(metadata?.attributes ?? {}).forEach(([attrName, attr]) => {
    const item = attr?.settings?.innerContent?.item;
    const fieldDefinition = fieldTypeMap[attrName];

    if (!item?.component) {
      return;
    }

    if (fieldDefinition) {
      item.component = {
        ...item.component,
        name: fieldDefinition.name,
        props: {
          ...(item.component.props ?? {}),
          ...(fieldDefinition.props ?? {}),
        },
      };
    }

    item.groupName = 'contentMainContent';
    item.groupSlug = 'contentMainContent';
  });

  return metadata;
};

const Placeholder = ({ children }) => (
  <div style={{
    padding: '2em 0',
    background: '#6c2eb9',
    color: '#fff',
    fontSize: '12px',
    fontWeight: '600',
    textAlign: 'center',
    borderRadius: '1em',
  }}>
    <h3 style={{
      color: '#000',
      textShadow: '1px 0px white, -1px 0px white, 0px 1px white, 0px -1px white',
      fontWeight: '900',
    }}>
      BeRocket Labels
    </h3>
    {children}
  </div>
);

const LabelPreview = ({
  attrs,
  metadata,
  placeholderLabel,
}) => {
  const [state, setState] = useState({
    html: '',
    isLoading: true,
    error: '',
  });
  const attrsKey = useMemo(() => JSON.stringify(attrs ?? {}), [attrs]);

  useEffect(() => {
    const config = previewConfig;
    if (!config?.ajaxUrl || !config?.nonce || !config?.action) {
      setState({
        html: '',
        isLoading: false,
        error: placeholderLabel,
      });
      return undefined;
    }

    const controller = new AbortController();
    const body = new FormData();
    body.append('action', config.action);
    body.append('nonce', config.nonce);
    body.append('module', metadata.name);
    body.append('attrs', attrsKey);

    setState((current) => ({
      ...current,
      isLoading: true,
      error: '',
    }));

    fetch(config.ajaxUrl, {
      body,
      method: 'POST',
      credentials: 'same-origin',
      signal: controller.signal,
    })
      .then((response) => response.json())
      .then((response) => {
        if (!response?.success) {
          throw new Error(response?.data?.message || placeholderLabel);
        }

        setState({
          html: response?.data?.html || '',
          isLoading: false,
          error: '',
        });
      })
      .catch((error) => {
        if (error.name === 'AbortError') {
          return;
        }

        setState({
          html: '',
          isLoading: false,
          error: error.message || placeholderLabel,
        });
      });

    return () => controller.abort();
  }, [attrsKey, metadata.name, placeholderLabel]);

  useEffect(() => {
    if (!state.isLoading && !state.error && state.html) {
      window.dispatchEvent(new Event('br_update_et_pb_brands_by_name'));
      document.dispatchEvent(new CustomEvent('brapl_divi5_label_preview_updated', { bubbles: true }));
    }
  }, [state.html, state.isLoading, state.error]);

  if (state.isLoading) {
    return <div className="et-fb-loader-wrapper"><div className="et-fb-loader" /></div>;
  }

  if (state.error || !state.html) {
    return <Placeholder>{state.error || placeholderLabel}</Placeholder>;
  }

  return <div dangerouslySetInnerHTML={{ __html: state.html }} />;
};

export const createBRAPLModule = (metadata, placeholderLabel) => ({
  metadata: normalizeMetadataFields(metadata),
  renderers: {
    edit: ({
      attrs,
      id,
      name,
      elements,
    }) => (
      <ModuleContainer
        attrs={attrs}
        elements={elements}
        id={id}
        moduleClassName={metadata.moduleClassName}
        name={name}
        scriptDataComponent={ModuleScriptData}
        stylesComponent={ModuleStyles}
        classnamesFunction={moduleClassnames}
      >
        {elements.styleComponents({
          attrName: 'module',
        })}
        <div className="et_pb_module_inner">
          <LabelPreview
            attrs={attrs}
            metadata={metadata}
            placeholderLabel={placeholderLabel}
          />
        </div>
      </ModuleContainer>
    ),
  },
  placeholderContent: {
    module: {
      meta: {
        adminLabel: {
          desktop: {
            value: metadata.title,
          },
        },
      },
    },
    ...metadata.defaultAttrs,
  },
});
