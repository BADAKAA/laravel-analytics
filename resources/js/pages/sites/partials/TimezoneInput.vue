<script setup lang="ts">
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';

type TimezoneInputProps = {
    id?: string;
    modelValue: string;
    placeholder?: string;
    disabled?: boolean;
};

const props = withDefaults(defineProps<TimezoneInputProps>(), {
    id: 'timezone',
    placeholder: 'UTC or Europe/London',
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const isFocused = ref(false);

const timezoneOffset = (timeZone: string): string => {
    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZoneName: 'shortOffset',
        });

        const parts = formatter.formatToParts(new Date());
        const offset = parts.find((part) => part.type === 'timeZoneName')?.value;

        return offset ? ` (${offset.replace('GMT', 'UTC')})` : '';
    } catch {
        return '';
    }
};

const allTimeZones = (() => {
    try {
        return Intl.supportedValuesOf('timeZone');
    } catch {
        return ['UTC'];
    }
})();

const filteredTimezones = computed(() => {
    if (!isFocused.value) {
        return [];
    }

    const query = props.modelValue.trim().toLowerCase();

    const values = query.length === 0
        ? allTimeZones
        : allTimeZones.filter((timeZone) =>
              timeZone.toLowerCase().includes(query),
          );

    return values.slice(0, 40).map((timeZone) => ({
        value: timeZone,
        label: `${timeZone}${timezoneOffset(timeZone)}`,
    }));
});

const updateValue = (value: string | number) => {
    emit('update:modelValue', String(value));
};

const chooseTimezone = (timeZone: string) => {
    emit('update:modelValue', timeZone);
    isFocused.value = false;
};

const onBlur = () => {
    // Delay so suggestion click can run before list closes.
    setTimeout(() => {
        isFocused.value = false;
    }, 100);
};
</script>

<template>
    <div class="relative">
        <Input
            :id="id"
            :model-value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            autocomplete="off"
            @focus="isFocused = true"
            @blur="onBlur"
            @update:model-value="updateValue"
        />

        <ul
            v-if="filteredTimezones.length > 0"
            class="absolute z-50 mt-2 max-h-56 w-full overflow-y-auto rounded-md border bg-background shadow-md"
        >
            <li
                v-for="timezone in filteredTimezones"
                :key="timezone.value"
                class="cursor-pointer px-3 py-2 text-sm hover:bg-accent"
                @mousedown.prevent="chooseTimezone(timezone.value)"
            >
                {{ timezone.label }}
            </li>
        </ul>
    </div>
</template>
