import { decodeEntities, decodeEntitiesDeep } from "@/lib/text/decode-entities"

describe("decodeEntities", () => {
  it("decodes the forms WordPress sends for titles", () => {
    expect(decodeEntities("Sunset and Soul R&amp;B Festival")).toBe("Sunset and Soul R&B Festival")
    expect(decodeEntities("R&#038;B Fest")).toBe("R&B Fest")
    expect(decodeEntities("Joe&#8217;s Tacos")).toBe("Joe’s Tacos")
    expect(decodeEntities("A &#x26; B")).toBe("A & B")
    expect(decodeEntities("Tom &amp;amp; Co")).toBe("Tom & Co")
    expect(decodeEntities("Fish &quot;n&quot; Chips")).toBe('Fish "n" Chips')
  })

  it("leaves plain text and unknown entities alone", () => {
    expect(decodeEntities("R&B")).toBe("R&B")
    expect(decodeEntities("Salt & Pepper")).toBe("Salt & Pepper")
    expect(decodeEntities("&madeup;")).toBe("&madeup;")
    expect(decodeEntities("")).toBe("")
  })

  it("decodes nested payloads but not HTML fields", () => {
    const payload = {
      items: [{ event_title: "R&amp;B", tags: ["Food &amp; Drink"], count: 3, ok: true, none: null }],
      html: "<p>R&amp;B</p>",
      body_html: "<b>&amp;</b>",
    }
    expect(decodeEntitiesDeep(payload)).toEqual({
      items: [{ event_title: "R&B", tags: ["Food & Drink"], count: 3, ok: true, none: null }],
      html: "<p>R&amp;B</p>",
      body_html: "<b>&amp;</b>",
    })
  })
})
