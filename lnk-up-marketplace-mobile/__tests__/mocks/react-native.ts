export const Platform = { OS: "ios", select: <T,>(spec: { ios?: T; default?: T }) => spec.ios ?? spec.default }
export const AppState = { currentState: "active", addEventListener: () => ({ remove: () => undefined }) }
export const StyleSheet = { create: <T,>(styles: T) => styles }
