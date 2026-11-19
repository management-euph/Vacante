<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

/**
 * Mutable builder for {@see BookingCartItem}.
 *
 * Cart assembly is inherently mutational today (Novoton's add_to_cart
 * accumulates fields from multiple sources: booking form, hotel row,
 * parsed guests data, pricing result, rooms data). A readonly DTO is
 * the wrong tool for assembly but the right tool for consumption, so
 * the builder mediates: set fields incrementally, then {@see self::build()}
 * emits the final immutable value.
 */
final class BookingCartItemBuilder
{
    private int $productId = 0;
    private int $bookingId = 0;
    private string $packageName = '';
    private int $numRooms = 1;
    /** @var list<array<string, mixed>> */
    private array $roomsData = [];
    private ?HotelSummary $hotel = null;
    private ?RoomSelection $room = null;
    private ?BoardSelection $board = null;
    private ?StayDates $stay = null;
    private ?GuestList $guests = null;
    private ?ContactInfo $contact = null;
    private ?BookingTerms $terms = null;
    private ?BookingPricing $pricing = null;

    public function productId(int $id): self
    {
        $this->productId = $id;
        return $this;
    }

    public function bookingId(int $id): self
    {
        $this->bookingId = $id;
        return $this;
    }

    public function packageName(string $name): self
    {
        $this->packageName = $name;
        return $this;
    }

    public function numRooms(int $count): self
    {
        $this->numRooms = $count;
        return $this;
    }

    /**
     * @param list<array<string, mixed>> $roomsData
     */
    public function roomsData(array $roomsData): self
    {
        $this->roomsData = $roomsData;
        return $this;
    }

    public function hotel(HotelSummary $hotel): self
    {
        $this->hotel = $hotel;
        return $this;
    }

    public function room(RoomSelection $room): self
    {
        $this->room = $room;
        return $this;
    }

    public function board(BoardSelection $board): self
    {
        $this->board = $board;
        return $this;
    }

    public function stay(StayDates $stay): self
    {
        $this->stay = $stay;
        return $this;
    }

    public function guests(GuestList $guests): self
    {
        $this->guests = $guests;
        return $this;
    }

    public function contact(ContactInfo $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function terms(BookingTerms $terms): self
    {
        $this->terms = $terms;
        return $this;
    }

    public function pricing(BookingPricing $pricing): self
    {
        $this->pricing = $pricing;
        return $this;
    }

    public function build(): BookingCartItem
    {
        if ($this->hotel === null) {
            throw new \LogicException('BookingCartItemBuilder requires hotel()');
        }
        if ($this->room === null) {
            throw new \LogicException('BookingCartItemBuilder requires room()');
        }
        if ($this->board === null) {
            throw new \LogicException('BookingCartItemBuilder requires board()');
        }
        if ($this->stay === null) {
            throw new \LogicException('BookingCartItemBuilder requires stay()');
        }
        if ($this->guests === null) {
            throw new \LogicException('BookingCartItemBuilder requires guests()');
        }
        if ($this->contact === null) {
            throw new \LogicException('BookingCartItemBuilder requires contact()');
        }
        if ($this->terms === null) {
            throw new \LogicException('BookingCartItemBuilder requires terms()');
        }
        if ($this->pricing === null) {
            throw new \LogicException('BookingCartItemBuilder requires pricing()');
        }

        return new BookingCartItem(
            productId: $this->productId,
            bookingId: $this->bookingId,
            packageName: $this->packageName,
            numRooms: $this->numRooms,
            roomsData: $this->roomsData,
            hotel: $this->hotel,
            room: $this->room,
            board: $this->board,
            stay: $this->stay,
            guests: $this->guests,
            contact: $this->contact,
            terms: $this->terms,
            pricing: $this->pricing,
        );
    }
}
