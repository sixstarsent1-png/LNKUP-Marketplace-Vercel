/** Unit tests for pure app logic (lib/). Screens are covered by Playwright against Expo web. */
module.exports = {
  testEnvironment: "node",
  roots: ["<rootDir>/__tests__"],
  transform: {
    "^.+\\.[jt]sx?$": ["babel-jest", { presets: ["babel-preset-expo"] }],
  },
  transformIgnorePatterns: ["node_modules/(?!(expo-.*|@expo/.*|expo|react-native|@react-native/.*)/)"],
  moduleNameMapper: {
    "^@/(.*)$": "<rootDir>/$1",
    "^expo-router$": "<rootDir>/__tests__/mocks/expo-router.ts",
    "^expo-constants$": "<rootDir>/__tests__/mocks/expo-constants.ts",
    "^expo-secure-store$": "<rootDir>/__tests__/mocks/expo-secure-store.ts",
    "^expo-notifications$": "<rootDir>/__tests__/mocks/expo-notifications.ts",
    "^expo-device$": "<rootDir>/__tests__/mocks/expo-device.ts",
    "^@react-native-async-storage/async-storage$": "<rootDir>/__tests__/mocks/async-storage.ts",
    "^react-native$": "<rootDir>/__tests__/mocks/react-native.ts",
  },
  testMatch: ["**/__tests__/**/*.test.ts?(x)"],
  testPathIgnorePatterns: ["/mocks/"],
}
