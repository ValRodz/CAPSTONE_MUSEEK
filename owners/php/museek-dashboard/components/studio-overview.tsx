import { Card, CardContent } from "@/components/ui/card"
import { CalendarIcon, UsersIcon } from "lucide-react"

interface StudioOverviewProps {
  className?: string
}

export function StudioOverview({ className }: StudioOverviewProps) {
  const stats = [
    {
      title: "Total Bookings",
      value: "128",
      change: "+12%",
      icon: CalendarIcon,
      description: "This month",
      color: "bg-blue-500/20",
      iconColor: "text-blue-500",
    },
    {
      title: "Active Clients",
      value: "42",
      change: "+5%",
      icon: UsersIcon,
      description: "Returning clients",
      color: "bg-purple-500/20",
      iconColor: "text-purple-500",
    },
  ]

  return (
    <div className={className}>
      <h2 className="text-xl font-bold mb-4">Studio Overview</h2>
      <div className="grid gap-4 md:grid-cols-2">
        {stats.map((stat, index) => (
          <Card key={index} className="bg-[#0a0a0a] border-[#222222]">
            <CardContent className="p-6">
              <div className="flex items-center justify-between mb-4">
                <div className={`p-2 rounded-full ${stat.color}`}>
                  <stat.icon className={`h-5 w-5 ${stat.iconColor}`} />
                </div>
                <span className="text-xs font-medium text-green-500">{stat.change}</span>
              </div>
              <div className="space-y-1">
                <h3 className="text-sm font-medium text-gray-400">{stat.title}</h3>
                <p className="text-2xl font-bold">{stat.value}</p>
                <p className="text-xs text-gray-500">{stat.description}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
