import { cachedRequest, clearRequestCache, invalidateCache, subscribeCache } from "@/lib/api/request-cache"

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe("request cache", () => {
  beforeEach(() => clearRequestCache())

  it("shares one in-flight request between concurrent callers", async () => {
    const fetcher = jest.fn(async () => "value")
    const [a, b] = await Promise.all([cachedRequest("k", fetcher), cachedRequest("k", fetcher)])
    expect(a).toBe("value")
    expect(b).toBe("value")
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it("reuses a fresh value and refetches when forced", async () => {
    const fetcher = jest.fn().mockResolvedValueOnce("one").mockResolvedValueOnce("two")
    expect(await cachedRequest("k", fetcher)).toBe("one")
    expect(await cachedRequest("k", fetcher)).toBe("one")
    expect(await cachedRequest("k", fetcher, { force: true })).toBe("two")
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it("never caches failures", async () => {
    const fetcher = jest.fn().mockRejectedValueOnce(new Error("down")).mockResolvedValueOnce("ok")
    await expect(cachedRequest("k", fetcher)).rejects.toThrow("down")
    expect(await cachedRequest("k", fetcher)).toBe("ok")
  })

  it("does not serve a read that started before invalidation", async () => {
    const stale = deferred<string>()
    const first = cachedRequest("org.requests:1", () => stale.promise)
    invalidateCache("org.requests:")
    const second = cachedRequest("org.requests:1", async () => "after-write")
    stale.resolve("before-write")
    expect(await first).toBe("before-write")
    expect(await second).toBe("after-write")
    expect(await cachedRequest("org.requests:1", async () => "unused")).toBe("after-write")
  })

  it("discards in-flight reads from a signed-out session", async () => {
    const old = deferred<string>()
    const pending = cachedRequest("vendor.requests", () => old.promise)
    clearRequestCache()
    old.resolve("previous-user")
    await pending
    expect(await cachedRequest("vendor.requests", async () => "new-user")).toBe("new-user")
  })

  it("notifies subscribers of fresh values only", async () => {
    const listener = jest.fn()
    const unsubscribe = subscribeCache("k", listener)
    await cachedRequest("k", async () => 3)
    expect(listener).toHaveBeenCalledWith(3)
    unsubscribe()
    await cachedRequest("k", async () => 4, { force: true })
    expect(listener).toHaveBeenCalledTimes(1)
  })
})
