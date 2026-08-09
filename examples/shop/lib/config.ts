export interface ShopConfig {
  agent: {
    isEnabled: boolean;
  };
  analytics: {
    speedInsights: {
      isEnabled: boolean;
    };
    vercel: {
      isEnabled: boolean;
    };
  };
  auth: {
    isEnabled: boolean;
  };
  pdp: {
    bundles: {
      isEnabled: boolean;
    };
    buyNow: {
      isEnabled: boolean;
    };
    complementaryProducts: {
      isEnabled: boolean;
    };
    quantityPicker: {
      isEnabled: boolean;
    };
    relatedProducts: {
      isEnabled: boolean;
    };
  };
  site: {
    name: string;
    url: string;
  };
}

function trimTrailingSlash(value: string): string {
  return value.replace(/\/+$/, "");
}

// Vercel injects bare domains (no protocol); NEXT_PUBLIC_BASE_URL follows the same convention.
const bareHost =
  trimTrailingSlash(process.env.NEXT_PUBLIC_BASE_URL || "") ||
  process.env.VERCEL_PROJECT_PRODUCTION_URL;

const defaultUrl = bareHost ? `https://${bareHost}` : "http://localhost:3000";

export const shopConfig = {
  agent: {
    isEnabled: false,
  },
  analytics: {
    speedInsights: {
      isEnabled: false,
    },
    vercel: {
      isEnabled: false,
    },
  },
  auth: {
    isEnabled: process.env.WOO_CUSTOMER_ACCOUNTS_ENABLED === "true",
  },
  pdp: {
    bundles: {
      isEnabled: true,
    },
    buyNow: {
      isEnabled: true,
    },
    complementaryProducts: {
      isEnabled: true,
    },
    quantityPicker: {
      isEnabled: true,
    },
    relatedProducts: {
      isEnabled: true,
    },
  },
  site: {
    name: "Woo Store",
    url: defaultUrl,
  },
} satisfies ShopConfig;
