"use client";

import type { Cart as WooCart } from "@woo/storefront-core";
import { RpcTransport } from "@woo/storefront-react";
import {
  createContext,
  type ReactNode,
  Suspense,
  use,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from "react";

import { CART_RESOLVED_EVENT } from "@/lib/cart/client";
import type { OptimisticProductInfo } from "@/lib/product";
import type { Cart, CartWarning } from "@/lib/types";
import { toCart } from "@/lib/woo/transforms";

export type CartMutationError = "add" | "remove" | "update";

interface CartContextType {
  addToCartOptimistic: (
    variantId: string,
    quantity: number,
    productInfo?: OptimisticProductInfo,
  ) => void;
  cart: Cart | null;
  cartWithPending: Cart | null;
  clearError: () => void;
  clearWarnings: () => void;
  isAddingToCart: boolean;
  isOverlayOpen: boolean;
  isUpdatingCart: boolean;
  lastError: CartMutationError | null;
  lastWarnings: CartWarning[];
  openOverlay: () => void;
  pendingQuantity: number;
  setCart: (cart: Cart | null) => void;
  setOverlayOpen: (open: boolean) => void;
  setWarnings: (warnings: CartWarning[]) => void;
  updateItemOptimistic: (lineId: string, quantity: number) => void;
}

const CartContext = createContext<CartContextType | null>(null);
const transport = new RpcTransport();

function CartProvider({
  children,
  initialCart,
}: {
  children: ReactNode;
  initialCart: Cart | null;
}) {
  const [cart, setCart] = useState(initialCart);
  const [isOverlayOpen, setOverlayOpen] = useState(false);
  const [isAddingToCart, setIsAddingToCart] = useState(false);
  const [pendingQuantity, setPendingQuantity] = useState(0);
  const [lastError, setLastError] = useState<CartMutationError | null>(null);
  const [lastWarnings, setWarnings] = useState<CartWarning[]>([]);
  const pending = useRef(0);

  const settle = useCallback((next: WooCart) => {
    setCart(toCart(next));
    pending.current = Math.max(0, pending.current - 1);
    setPendingQuantity(pending.current);
    setIsAddingToCart(false);
  }, []);

  const fail = useCallback((kind: CartMutationError) => {
    pending.current = Math.max(0, pending.current - 1);
    setPendingQuantity(pending.current);
    setIsAddingToCart(false);
    setLastError(kind);
  }, []);

  const addToCartOptimistic = useCallback(
    (variantId: string, quantity: number) => {
      const id = Number(variantId);
      if (!Number.isInteger(id) || id <= 0) return;
      setLastError(null);
      setIsAddingToCart(true);
      setOverlayOpen(true);
      pending.current += quantity;
      setPendingQuantity(pending.current);
      void transport
        .mutate<WooCart>("cart.addItem", { id, quantity })
        .then(settle, () => fail("add"));
    },
    [fail, settle],
  );

  const updateItemOptimistic = useCallback(
    (key: string, quantity: number) => {
      if (!key || quantity < 0 || quantity > 99) return;
      setLastError(null);
      pending.current += 1;
      setPendingQuantity(pending.current);
      const operation = quantity === 0 ? "cart.removeItem" : "cart.updateItem";
      const variables = quantity === 0 ? { key } : { key, quantity };
      void transport
        .mutate<WooCart>(operation, variables)
        .then(settle, () => fail(quantity === 0 ? "remove" : "update"));
    },
    [fail, settle],
  );

  useEffect(() => {
    const onResolved = (event: Event) => settle((event as CustomEvent<WooCart>).detail);
    document.addEventListener(CART_RESOLVED_EVENT, onResolved);
    return () => document.removeEventListener(CART_RESOLVED_EVENT, onResolved);
  }, [settle]);

  return (
    <CartContext.Provider
      value={{
        addToCartOptimistic,
        cart,
        cartWithPending: cart,
        clearError: () => setLastError(null),
        clearWarnings: () => setWarnings([]),
        isAddingToCart,
        isOverlayOpen,
        isUpdatingCart: pendingQuantity > 0,
        lastError,
        lastWarnings,
        openOverlay: () => setOverlayOpen(true),
        pendingQuantity,
        setCart,
        setOverlayOpen,
        setWarnings,
        updateItemOptimistic,
      }}
    >
      {children}
    </CartContext.Provider>
  );
}

export function useCart() {
  const context = useContext(CartContext);
  if (!context) throw new Error("useCart must be used within CartProviderWrapper");
  return context;
}

export function useSeedCart(initialCart: Cart | null) {
  const context = useContext(CartContext);
  useEffect(() => {
    if (context && initialCart) context.setCart(initialCart);
  }, [context, initialCart]);
}

export function CartProviderWrapper({
  cartData,
  children,
}: {
  cartData: Promise<Cart | null>;
  children: ReactNode;
}) {
  return (
    <CartProvider initialCart={null}>
      <Suspense fallback={null}>
        <CartSeeder cartData={cartData} />
      </Suspense>
      {children}
    </CartProvider>
  );
}

function CartSeeder({ cartData }: { cartData: Promise<Cart | null> }) {
  useSeedCart(use(cartData));
  return null;
}

const CartRenderContext = createContext<Cart | null>(null);

export function CartContextSync({ cart, children }: { cart: Cart | null; children: ReactNode }) {
  const { cart: currentCart } = useCart();
  useSeedCart(cart);
  return (
    <CartRenderContext.Provider value={currentCart ?? cart}>{children}</CartRenderContext.Provider>
  );
}

export function useCartRender() {
  return useContext(CartRenderContext);
}
