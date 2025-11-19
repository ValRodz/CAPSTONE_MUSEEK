import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"

type BookingStatus = "confirmed" | "pending" | "cancelled"

interface Booking {
  id: string
  customerName: string
  customerInitials: string
  studio: string
  date: string
  time: string
  status: BookingStatus
  amount: number
}

const bookings: Booking[] = [
  {
    id: "1",
    customerName: "John Doe",
    customerInitials: "JD",
    studio: "Studio A",
    date: "2023-05-15",
    time: "10:00 AM - 12:00 PM",
    status: "confirmed",
    amount: 120,
  },
  {
    id: "2",
    customerName: "Jane Smith",
    customerInitials: "JS",
    studio: "Studio B",
    date: "2023-05-16",
    time: "2:00 PM - 4:00 PM",
    status: "pending",
    amount: 150,
  },
  {
    id: "3",
    customerName: "Mike Johnson",
    customerInitials: "MJ",
    studio: "Studio C",
    date: "2023-05-17",
    time: "9:00 AM - 11:00 AM",
    status: "confirmed",
    amount: 100,
  },
  {
    id: "4",
    customerName: "Sarah Williams",
    customerInitials: "SW",
    studio: "Studio A",
    date: "2023-05-18",
    time: "3:00 PM - 5:00 PM",
    status: "cancelled",
    amount: 120,
  },
  {
    id: "5",
    customerName: "David Brown",
    customerInitials: "DB",
    studio: "Studio B",
    date: "2023-05-19",
    time: "1:00 PM - 3:00 PM",
    status: "confirmed",
    amount: 150,
  },
]

export function RecentBookings() {
  return (
    <Card className="bg-[#0a0a0a] border-[#222222]">
      <CardHeader className="pb-2">
        <CardTitle>Recent Bookings</CardTitle>
        <CardDescription>Your latest studio bookings</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-4">
          {bookings.map((booking) => (
            <div key={booking.id} className="flex items-center gap-4">
              <Avatar>
                <AvatarFallback>{booking.customerInitials}</AvatarFallback>
              </Avatar>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium">{booking.customerName}</p>
                <p className="text-xs text-gray-400">{booking.studio}</p>
                <p className="text-xs text-gray-400">
                  {booking.date} • {booking.time}
                </p>
              </div>
              <div className="flex flex-col items-end gap-1">
                <Badge
                  variant={
                    booking.status === "confirmed"
                      ? "destructive"
                      : booking.status === "pending"
                        ? "outline"
                        : "secondary"
                  }
                  className={
                    booking.status === "confirmed"
                      ? "bg-red-600 hover:bg-red-700"
                      : booking.status === "pending"
                        ? "border-gray-500 text-gray-300"
                        : "bg-gray-700 hover:bg-gray-600"
                  }
                >
                  {booking.status}
                </Badge>
                <span className="text-sm">${booking.amount}</span>
              </div>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  )
}
