import { SkeletonList } from "@/components/marketplace/states"

export default function RootLoading() {
  return (
    <div className="mx-auto w-full max-w-2xl px-4 py-8 md:max-w-5xl md:px-8">
      <SkeletonList count={3} />
    </div>
  )
}
