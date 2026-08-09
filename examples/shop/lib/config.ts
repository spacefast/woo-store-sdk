export interface ShopConfig {
  analytics: {
    speedInsights: {
      isEnabled: boolean;
    };
    vercel: {
      isEnabled: boolean;
    };
  };
  pdp: {
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
  analytics: {
    speedInsights: {
      isEnabled: false,
    },
    vercel: {
      isEnabled: false,
    },
  },
  pdp: {
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
